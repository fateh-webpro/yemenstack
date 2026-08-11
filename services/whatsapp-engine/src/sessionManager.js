const { config } = require('./config');

const DEFAULT_STATE = 'stopped';
const SESSION_NAME_PATTERN = /^wa_[a-z0-9_]+$/;
const ACTIVE_STATES = new Set([
  'starting',
  'waiting_for_qr',
  'authenticated',
  'ready',
  'running',
]);

const sanitizeError = (error) => {
  if (!error) {
    return null;
  }

  if (error instanceof Error) {
    return {
      name: error.name,
      message: error.message,
      code: error.code || null,
    };
  }

  return {
    name: 'Error',
    message: String(error),
    code: null,
  };
};

const sanitizeReason = (reason) => {
  if (reason === undefined || reason === null || reason === '') {
    return null;
  }

  return String(reason);
};

const isSafeSessionName = (sessionName) => {
  if (typeof sessionName !== 'string') {
    return false;
  }

  if (!SESSION_NAME_PATTERN.test(sessionName)) {
    return false;
  }

  return !sessionName.includes('..') && !sessionName.includes('/') && !sessionName.includes('\\');
};

class SessionManager {
  constructor(dependencies = {}) {
    this.dependencies = {
      createClient: dependencies.createClient || (async () => ({
        initialize: async () => {},
        destroy: async () => {},
        sendMessage: async () => {},
      })),
      createMessageWorker: dependencies.createMessageWorker || null,
      createStatusClient: dependencies.createStatusClient || null,
      destroyClient: dependencies.destroyClient || null,
      startPolling: dependencies.startPolling || (() => null),
      stopPolling: dependencies.stopPolling || (() => null),
      setTimeout: dependencies.setTimeout || global.setTimeout,
      clearTimeout: dependencies.clearTimeout || global.clearTimeout,
      readinessTimeoutMs: dependencies.readinessTimeoutMs || config.whatsappRestartTimeoutMs,
      logger: dependencies.logger || {
        info: () => {},
        warn: () => {},
        error: () => {},
      },
    };

    this.sessions = new Map();
    this.sessionNames = new Map();
  }

  has(accountId) {
    return this.sessions.has(this.normalizeAccountId(accountId));
  }

  get(accountId) {
    return this.sessions.get(this.normalizeAccountId(accountId)) || null;
  }

  list() {
    return Array.from(this.sessions.values());
  }

  async start(sessionDescriptor) {
    const normalized = this.validateSessionDescriptor(sessionDescriptor);
    const key = this.normalizeAccountId(normalized.accountId);

    let context = this.sessions.get(key);

    if (!context) {
      this.ensureSessionNameNotInUse(normalized.sessionName, normalized.accountId);
      context = this.createContext(normalized);
      this.attachStatusClient(context);
      this.attachMessageWorker(context);
      this.sessions.set(key, context);
      this.sessionNames.set(normalized.sessionName, key);
    } else {
      this.ensureDescriptorMatchesContext(context, normalized);
    }

    context.desiredState = 'running';
    if (normalized.hasAutomaticSendingEnabled) {
      context.automaticSendingEnabled = normalized.automaticSendingEnabled;
    }
    this.touchContext(context);

    if (context.startPromise) {
      return context.startPromise;
    }

    if (context.stopPromise) {
      return context.stopPromise.then(() => this.getSnapshot(context.accountId));
    }

    if (context.isStopping || (context.isRestarting && context.actualState === 'restarting')) {
      return this.getSnapshot(context.accountId);
    }

    if (context.client && context.actualState !== 'stopped') {
      return this.getSnapshot(context.accountId);
    }

    if (ACTIVE_STATES.has(context.actualState)) {
      return this.getSnapshot(context.accountId);
    }

    const generation = context.generation + 1;
    context.generation = generation;
    context.actualState = 'starting';
    context.isStarting = true;
    context.isReady = false;
    context.lastError = null;
    this.cancelReadinessTimeout(context);

    context.startPromise = (async () => {
      const callbacks = this.createCallbacks(context.accountId, generation);
      const descriptor = this.buildSessionDescriptor(context);
      const client = await this.dependencies.createClient(descriptor, callbacks);

      if (!this.isCurrentGeneration(context.accountId, generation)) {
        await this.destroyClient(client, context, 'stale_client_after_create');
        return this.getSnapshot(context.accountId);
      }

      context.client = client;
      this.touchContext(context);
      await this.reportStatus(context.accountId, generation, 'connecting');

      if (client && typeof client.initialize === 'function') {
        await client.initialize();
      }

      if (!this.isCurrentGeneration(context.accountId, generation)) {
        await this.destroyClient(client, context, 'stale_client_after_initialize');
        return this.getSnapshot(context.accountId);
      }

      this.touchContext(context);
      return this.getSnapshot(context.accountId);
    })().catch(async (error) => {
      context.lastError = sanitizeError(error);
      context.actualState = 'error';
      context.cancelReadinessTimeout = false;
      this.cancelReadinessTimeout(context);
      this.touchContext(context);

      await this.reportStatus(context.accountId, generation, 'error', {
        error_message: error?.message || 'Failed to start managed session.',
        error_code: error?.code || null,
      });

      if (context.client) {
        try {
          await this.destroyClient(context.client, context, 'start_failure');
        } catch (destroyError) {
          context.lastError = sanitizeError(destroyError);
          this.dependencies.logger.warn('Failed to destroy session client cleanly.', {
            accountId: context.accountId,
            reason: 'start_failure',
            message: destroyError.message,
          });
        }

        context.client = null;
      }

      throw error;
    }).finally(() => {
      context.isStarting = false;
      context.startPromise = null;
      this.touchContext(context);
    });

    return context.startPromise;
  }

  async stop(accountId) {
    const context = this.requireContext(accountId);

    context.desiredState = 'stopped';
    this.touchContext(context);

    if (context.stopPromise) {
      return context.stopPromise;
    }

    if (context.actualState === 'stopped' && !context.client && !context.isStarting && !context.isRestarting) {
      return this.getSnapshot(accountId);
    }

    context.actualState = 'stopping';
    context.isStopping = true;
    this.touchContext(context);

    context.stopPromise = this.stopContext(context, {
      preserveDesiredState: false,
      reason: 'manual_stop',
    }).finally(() => {
      context.stopPromise = null;
    });

    return context.stopPromise;
  }

  async restart(accountId, reason = 'manual_restart') {
    const context = this.requireContext(accountId);

    if (context.restartPromise) {
      return context.restartPromise;
    }

    context.isRestarting = true;
    context.actualState = 'restarting';
    context.desiredState = 'running';
    this.touchContext(context);

    context.restartPromise = (async () => {
      await this.stopContext(context, {
        preserveDesiredState: true,
        reason,
      });

      return this.start({
        accountId: context.accountId,
        sessionName: context.sessionName,
        desiredState: 'running',
      });
    })().finally(() => {
      context.isRestarting = false;
      context.restartPromise = null;
      this.touchContext(context);
    });

    return context.restartPromise;
  }

  async remove(accountId) {
    const key = this.normalizeAccountId(accountId);
    const context = this.sessions.get(key);

    if (!context) {
      return false;
    }

    if (context.client || context.actualState !== 'stopped' || context.isStarting || context.isRestarting || context.isStopping || context.stopPromise) {
      await this.stop(accountId);
    }

    this.sessions.delete(key);
    this.sessionNames.delete(context.sessionName);

    return true;
  }

  async shutdownAll() {
    const results = [];

    for (const context of this.list()) {
      try {
        await this.stop(context.accountId);
        results.push({
          accountId: context.accountId,
          success: true,
        });
      } catch (error) {
        results.push({
          accountId: context.accountId,
          success: false,
          error: sanitizeError(error),
        });
      }
    }

    return {
      total: results.length,
      succeeded: results.filter((result) => result.success).length,
      failed: results.filter((result) => !result.success).length,
      results,
    };
  }

  async ensureMessageWorkerStarted(accountId) {
    const context = this.get(accountId);

    if (!context) {
      return null;
    }

    if (
      context.desiredState !== 'running'
      || !context.isReady
      || context.isStopping
      || context.isRestarting
      || context.actualState === 'stopped'
      || context.actualState === 'error'
    ) {
      return this.buildSnapshot(context);
    }

    return this.startMessageWorker(context);
  }

  getSnapshot(accountId) {
    const context = this.requireContext(accountId);
    return this.buildSnapshot(context);
  }

  getAllSnapshots() {
    return this.list().map((context) => this.buildSnapshot(context));
  }

  async stopContext(context, options = {}) {
    const { preserveDesiredState = false, reason = 'stop' } = options;
    const activeClient = context.client;
    let destroyFailed = false;

    try {
      this.cancelReadinessTimeout(context);
      await this.stopMessageWorker(context, reason);

      if (context.pollTimer !== null) {
        try {
          this.dependencies.stopPolling(context);
        } finally {
          context.pollTimer = null;
        }
      } else {
        this.dependencies.stopPolling(context);
      }

      if (activeClient) {
        try {
          await this.destroyClient(activeClient, context, reason);
        } catch (error) {
          destroyFailed = true;
          context.lastError = sanitizeError(error);
          context.actualState = 'error';
          this.dependencies.logger.warn('Failed to destroy session client cleanly.', {
            accountId: context.accountId,
            reason,
            message: error.message,
          });
        }
      }

      context.isReady = false;
      context.isCycleRunning = false;

      if (!preserveDesiredState) {
        context.desiredState = 'stopped';
      }

      if (destroyFailed) {
        context.client = activeClient;
      } else {
        context.client = null;
        context.actualState = 'stopped';
      }

      this.touchContext(context);
      return this.buildSnapshot(context);
    } finally {
      context.isStopping = false;
      this.touchContext(context);
    }
  }

  async destroyClient(client, context, reason) {
    if (!client) {
      return;
    }

    if (typeof this.dependencies.destroyClient === 'function') {
      return this.dependencies.destroyClient(client, context, reason);
    }

    if (typeof client.destroy === 'function') {
      return client.destroy();
    }
  }

  createCallbacks(accountId, generation) {
    const getContext = () => this.get(accountId);
    const resolveContext = () => {
      const context = getContext();

      if (!this.shouldHandleCallback(context, generation)) {
        return null;
      }

      return context;
    };

    return {
      onQr: (qr) => {
        const context = resolveContext();
        if (!context) {
          return;
        }

        context.actualState = 'waiting_for_qr';
        this.dependencies.logger.info('Managed session QR is required.', {
          accountId: context.accountId,
          sessionName: context.sessionName,
          generation,
        });
        this.touchContext(context);
        void (async () => {
          await this.storeQr(context.accountId, generation, qr);
          await this.reportStatus(context.accountId, generation, 'qr_required');
        })();
      },
      onAuthenticated: () => {
        const context = resolveContext();
        if (!context) {
          return;
        }

        context.actualState = 'authenticated';
        this.armReadinessTimeout(context, generation);
        this.dependencies.logger.info('Managed session authenticated.', {
          accountId: context.accountId,
          sessionName: context.sessionName,
          generation,
        });
        this.touchContext(context);
        void this.reportStatus(context.accountId, generation, 'authenticated');
      },
      onReady: () => {
        const context = resolveContext();
        if (!context) {
          return;
        }

        this.cancelReadinessTimeout(context);
        context.isReady = true;
        context.actualState = 'ready';
        context.lastError = null;
        context.pollTimer = this.dependencies.startPolling(context) || context.pollTimer || null;
        this.dependencies.logger.info('Managed session ready.', {
          accountId: context.accountId,
          sessionName: context.sessionName,
          generation,
        });
        this.touchContext(context);
        void this.reportStatus(context.accountId, generation, 'connected', {
          last_seen_at: new Date().toISOString(),
        });
        void this.startMessageWorker(context);
      },
      onDisconnected: (reason) => {
        const context = resolveContext();
        if (!context) {
          return;
        }

        this.cancelReadinessTimeout(context);
        context.isReady = false;
        context.actualState = 'stopped';
        context.lastError = reason ? sanitizeError(new Error(String(reason))) : context.lastError;

        try {
          this.dependencies.stopPolling(context);
        } finally {
          context.pollTimer = null;
        }

        this.dependencies.logger.warn('Managed session disconnected.', {
          accountId: context.accountId,
          sessionName: context.sessionName,
          generation,
          reason: sanitizeReason(reason),
        });
        void this.stopMessageWorker(context, 'disconnected_event');
        this.touchContext(context);
        void this.reportStatus(context.accountId, generation, 'disconnected', {
          reason: sanitizeReason(reason),
        });
      },
      onError: (error) => {
        const context = resolveContext();
        if (!context) {
          return;
        }

        this.cancelReadinessTimeout(context);
        context.isReady = false;
        context.actualState = 'error';
        context.lastError = sanitizeError(error);
        this.dependencies.logger.error('Managed session emitted an error event.', {
          accountId: context.accountId,
          sessionName: context.sessionName,
          generation,
          code: error?.code || null,
          message: error?.message || String(error),
        });
        void this.stopMessageWorker(context, 'error_event');
        this.touchContext(context);
        void this.reportStatus(context.accountId, generation, 'error', {
          error_code: error?.code || null,
          error_message: error?.message || String(error),
        });
      },
      onStateChanged: (state) => {
        const context = resolveContext();
        if (!context) {
          return;
        }

        this.dependencies.logger.info('Managed session lifecycle state updated.', {
          accountId: context.accountId,
          sessionName: context.sessionName,
          generation,
          state: sanitizeReason(state) || 'unknown',
        });
        this.touchContext(context);
      },
      onLoadingScreen: (percent, message) => {
        const context = resolveContext();
        if (!context) {
          return;
        }

        this.dependencies.logger.info('Managed session loading screen update.', {
          accountId: context.accountId,
          sessionName: context.sessionName,
          generation,
          percent: Number.isFinite(percent) ? percent : null,
          message: sanitizeReason(message),
        });
        this.touchContext(context);
      },
    };
  }

  createContext(sessionDescriptor) {
    const now = new Date().toISOString();

    return {
      accountId: sessionDescriptor.accountId,
      sessionName: sessionDescriptor.sessionName,
      desiredState: sessionDescriptor.desiredState || 'stopped',
      automaticSendingEnabled: Boolean(sessionDescriptor.automaticSendingEnabled),
      actualState: DEFAULT_STATE,
      client: null,
      generation: 0,
      isReady: false,
      isStarting: false,
      isStopping: false,
      isRestarting: false,
      startPromise: null,
      stopPromise: null,
      restartPromise: null,
      pollTimer: null,
      isCycleRunning: false,
      readinessTimer: null,
      waitingForReady: false,
      readinessDeadlineAt: null,
      messageWorker: null,
      statusClient: null,
      lastError: null,
      createdAt: now,
      updatedAt: now,
    };
  }

  buildSessionDescriptor(context) {
    return {
      accountId: context.accountId,
      sessionName: context.sessionName,
      desiredState: context.desiredState,
      automaticSendingEnabled: context.automaticSendingEnabled,
      generation: context.generation,
    };
  }

  buildSnapshot(context) {
    return {
      accountId: context.accountId,
      sessionName: context.sessionName,
      state: context.actualState,
      desiredState: context.desiredState,
      automaticSendingEnabled: context.automaticSendingEnabled,
      generation: context.generation,
      isReady: context.isReady,
      waitingForReady: context.waitingForReady,
      readinessDeadlineAt: context.readinessDeadlineAt,
      hasClient: Boolean(context.client),
      hasMessageWorker: Boolean(context.messageWorker),
      hasStatusClient: Boolean(context.statusClient),
      messageWorker: typeof context.messageWorker?.getSnapshot === 'function'
        ? context.messageWorker.getSnapshot()
        : null,
      lastError: context.lastError,
      createdAt: context.createdAt,
      updatedAt: context.updatedAt,
    };
  }

  attachStatusClient(context) {
    if (typeof this.dependencies.createStatusClient !== 'function') {
      return;
    }

    context.statusClient = this.dependencies.createStatusClient(this.buildSessionDescriptor(context));
  }

  attachMessageWorker(context) {
    if (typeof this.dependencies.createMessageWorker !== 'function') {
      return;
    }

    context.messageWorker = this.dependencies.createMessageWorker(
      this.buildSessionDescriptor(context),
      {
        getContext: () => context,
        getWhatsappClient: () => context.client,
        isReady: () => context.isReady,
        getGeneration: () => context.generation,
      },
    );
  }

  armReadinessTimeout(context, generation) {
    const readinessTimeoutMs = this.dependencies.readinessTimeoutMs;

    if (!Number.isInteger(readinessTimeoutMs) || readinessTimeoutMs <= 0) {
      return;
    }

    if (context.waitingForReady && context.readinessTimer) {
      return;
    }

    this.cancelReadinessTimeout(context);

    context.waitingForReady = true;
    context.readinessDeadlineAt = new Date(Date.now() + readinessTimeoutMs).toISOString();
    context.readinessTimer = this.dependencies.setTimeout(async () => {
      await this.handleReadinessTimeout(context.accountId, generation);
    }, readinessTimeoutMs);
    this.touchContext(context);
  }

  cancelReadinessTimeout(context) {
    if (context.readinessTimer) {
      this.dependencies.clearTimeout(context.readinessTimer);
      context.readinessTimer = null;
    }

    context.waitingForReady = false;
    context.readinessDeadlineAt = null;
  }

  async handleReadinessTimeout(accountId, generation) {
    const context = this.get(accountId);

    if (!context || !this.isCurrentGeneration(accountId, generation) || !context.waitingForReady || context.isReady) {
      return false;
    }

    this.cancelReadinessTimeout(context);
    context.actualState = 'stopping';
    context.isReady = false;
    context.lastError = sanitizeError(Object.assign(
      new Error('Managed session readiness timeout after authentication.'),
      { code: 'MANAGED_SESSION_READY_TIMEOUT' },
    ));

    try {
      this.dependencies.stopPolling(context);
    } finally {
      context.pollTimer = null;
    }

    await this.stopMessageWorker(context, 'readiness_timeout');

    this.dependencies.logger.error('Managed session readiness timeout reached before ready.', {
      accountId: context.accountId,
      sessionName: context.sessionName,
      generation,
      timeout_ms: this.dependencies.readinessTimeoutMs,
    });

    await this.reportStatus(accountId, generation, 'error', {
      error_code: 'MANAGED_SESSION_READY_TIMEOUT',
      error_message: 'Managed session readiness timeout after authentication.',
    });

    context.isStopping = true;
    context.stopPromise = this.stopContext(context, {
      preserveDesiredState: true,
      reason: 'readiness_timeout',
    }).finally(() => {
      context.stopPromise = null;
    });

    await context.stopPromise;

    this.touchContext(context);
    return true;
  }

  async startMessageWorker(context) {
    if (!context.messageWorker || typeof context.messageWorker.start !== 'function') {
      return null;
    }

    try {
      return await context.messageWorker.start();
    } catch (error) {
      context.lastError = sanitizeError(error);
      this.dependencies.logger.error('Failed to start session message worker.', {
        accountId: context.accountId,
        message: error.message,
      });
      return null;
    }
  }

  async stopMessageWorker(context, reason = 'stop') {
    if (!context.messageWorker || typeof context.messageWorker.stop !== 'function') {
      return null;
    }

    try {
      return await context.messageWorker.stop(reason);
    } catch (error) {
      context.lastError = sanitizeError(error);
      this.dependencies.logger.warn('Failed to stop session message worker cleanly.', {
        accountId: context.accountId,
        reason,
        message: error.message,
      });
      return null;
    }
  }

  async reportStatus(accountId, generation, status, extra = {}) {
    if (!this.isCurrentGeneration(accountId, generation)) {
      return false;
    }

    const context = this.get(accountId);

    if (!context || !context.statusClient || typeof context.statusClient.updateSessionStatus !== 'function') {
      return false;
    }

    const payload = {};

    for (const [key, value] of Object.entries(extra)) {
      if (value === undefined || value === null || value === '') {
        continue;
      }

      payload[key] = value;
    }

    try {
      await context.statusClient.updateSessionStatus(status, payload);
      return true;
    } catch (error) {
      this.dependencies.logger.warn('Failed to update managed session status.', {
        accountId: context.accountId,
        sessionName: context.sessionName,
        generation,
        status,
        code: error?.code || null,
        message: error?.message || String(error),
      });
      return false;
    }
  }

  async storeQr(accountId, generation, qr, extra = {}) {
    if (!this.isCurrentGeneration(accountId, generation)) {
      return false;
    }

    const context = this.get(accountId);

    if (!context || !context.statusClient || typeof context.statusClient.storeSessionQr !== 'function') {
      return false;
    }

    try {
      await context.statusClient.storeSessionQr(qr, extra);
      return true;
    } catch (error) {
      this.dependencies.logger.warn('Failed to store managed session QR.', {
        accountId: context.accountId,
        sessionName: context.sessionName,
        generation,
        code: error?.code || null,
        message: error?.message || String(error),
      });
      return false;
    }
  }

  ensureDescriptorMatchesContext(context, sessionDescriptor) {
    if (context.sessionName !== sessionDescriptor.sessionName) {
      throw new Error(`Session already exists for account ${sessionDescriptor.accountId} with a different session name.`);
    }

    const registeredAccountKey = this.sessionNames.get(sessionDescriptor.sessionName);
    const currentAccountKey = this.normalizeAccountId(sessionDescriptor.accountId);

    if (registeredAccountKey && registeredAccountKey !== currentAccountKey) {
      throw new Error(`Session name ${sessionDescriptor.sessionName} is already in use by another account.`);
    }
  }

  ensureSessionNameNotInUse(sessionName, accountId) {
    const existingAccountKey = this.sessionNames.get(sessionName);

    if (existingAccountKey && existingAccountKey !== this.normalizeAccountId(accountId)) {
      throw new Error(`Session name ${sessionName} is already in use by another account.`);
    }
  }

  validateSessionDescriptor(sessionDescriptor) {
    if (!sessionDescriptor || typeof sessionDescriptor !== 'object') {
      throw new Error('Session descriptor is required.');
    }

    if (sessionDescriptor.accountId === undefined || sessionDescriptor.accountId === null || sessionDescriptor.accountId === '') {
      throw new Error('accountId is required.');
    }

    if (!isSafeSessionName(sessionDescriptor.sessionName)) {
      throw new Error('sessionName is invalid.');
    }

    return {
      accountId: sessionDescriptor.accountId,
      sessionName: sessionDescriptor.sessionName,
      desiredState: sessionDescriptor.desiredState || 'stopped',
      automaticSendingEnabled: Boolean(sessionDescriptor.automaticSendingEnabled),
      hasAutomaticSendingEnabled: Object.prototype.hasOwnProperty.call(sessionDescriptor, 'automaticSendingEnabled'),
    };
  }

  requireContext(accountId) {
    const context = this.get(accountId);

    if (!context) {
      throw new Error(`Session ${accountId} was not found.`);
    }

    return context;
  }

  isCurrentGeneration(accountId, generation) {
    const context = this.get(accountId);
    return Boolean(context) && context.generation === generation;
  }

  shouldHandleCallback(context, generation) {
    if (!context || context.generation !== generation) {
      return false;
    }

    if (context.isStopping || context.actualState === 'stopped' || context.actualState === 'restarting') {
      return false;
    }

    return true;
  }

  normalizeAccountId(accountId) {
    return String(accountId);
  }

  touchContext(context) {
    context.updatedAt = new Date().toISOString();
  }
}

module.exports = {
  SessionManager,
  isSafeSessionName,
  sanitizeError,
};