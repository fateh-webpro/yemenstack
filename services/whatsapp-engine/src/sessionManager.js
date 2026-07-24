const DEFAULT_STATE = 'stopped';
const SESSION_NAME_PATTERN = /^wa_[a-z0-9_]+$/;

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
      })),
      createMessageWorker: dependencies.createMessageWorker || null,
      createStatusClient: dependencies.createStatusClient || null,
      destroyClient: dependencies.destroyClient || null,
      startPolling: dependencies.startPolling || (() => null),
      stopPolling: dependencies.stopPolling || (() => null),
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
    this.touchContext(context);

    if (context.startPromise) {
      return context.startPromise;
    }

    if ((context.actualState === 'running' || context.actualState === 'starting') && !context.isStopping && !context.isRestarting) {
      return this.getSnapshot(context.accountId);
    }

    const generation = context.generation + 1;
    context.generation = generation;
    context.actualState = 'starting';
    context.isStarting = true;
    context.lastError = null;
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

      if (context.actualState === 'starting') {
        context.actualState = 'running';
      }

      this.touchContext(context);
      return this.getSnapshot(context.accountId);
    })().catch(async (error) => {
      context.lastError = sanitizeError(error);
      context.actualState = 'error';
      this.touchContext(context);

      await this.reportStatus(context.accountId, generation, 'error', {
        error_message: error?.message || 'Failed to start managed session.',
        error_code: error?.code || null,
      });

      if (context.client) {
        await this.destroyClient(context.client, context, 'start_failure');
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

    if (context.client || context.actualState !== 'stopped' || context.isStarting || context.isRestarting) {
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

  getSnapshot(accountId) {
    const context = this.requireContext(accountId);
    return this.buildSnapshot(context);
  }

  getAllSnapshots() {
    return this.list().map((context) => this.buildSnapshot(context));
  }

  async stopContext(context, options = {}) {
    const { preserveDesiredState = false, reason = 'stop' } = options;

    try {
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

      if (context.client) {
        try {
          await this.destroyClient(context.client, context, reason);
        } catch (error) {
          context.lastError = sanitizeError(error);
          this.dependencies.logger.warn('Failed to destroy session client cleanly.', {
            accountId: context.accountId,
            reason,
            message: error.message,
          });
        }
      }

      context.client = null;
      context.isReady = false;
      context.isCycleRunning = false;
      context.actualState = 'stopped';

      if (!preserveDesiredState) {
        context.desiredState = 'stopped';
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
    const isCurrent = () => this.isCurrentGeneration(accountId, generation);
    const getContext = () => this.get(accountId);

    return {
      onQr: () => {
        const context = getContext();
        if (!context || !isCurrent()) {
          return;
        }

        this.dependencies.logger.info('Managed session QR is required.', {
          accountId: context.accountId,
          sessionName: context.sessionName,
          generation,
        });
        this.touchContext(context);
        void this.reportStatus(context.accountId, generation, 'qr_required');
      },
      onAuthenticated: () => {
        const context = getContext();
        if (!context || !isCurrent()) {
          return;
        }

        this.dependencies.logger.info('Managed session authenticated.', {
          accountId: context.accountId,
          sessionName: context.sessionName,
          generation,
        });
        this.touchContext(context);
        void this.reportStatus(context.accountId, generation, 'authenticated');
      },
      onReady: () => {
        const context = getContext();
        if (!context || !isCurrent()) {
          return;
        }

        context.isReady = true;
        context.actualState = 'running';
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
        const context = getContext();
        if (!context || !isCurrent()) {
          return;
        }

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
        const context = getContext();
        if (!context || !isCurrent()) {
          return;
        }

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
    };
  }

  createContext(sessionDescriptor) {
    const now = new Date().toISOString();

    return {
      accountId: sessionDescriptor.accountId,
      sessionName: sessionDescriptor.sessionName,
      desiredState: sessionDescriptor.desiredState || 'stopped',
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
    };
  }

  buildSnapshot(context) {
    return {
      accountId: context.accountId,
      sessionName: context.sessionName,
      state: context.actualState,
      desiredState: context.desiredState,
      generation: context.generation,
      isReady: context.isReady,
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