const { config } = require('./config');
const { calculateRecoveryDelayMs, getErrorMessage } = require('./sessionRecovery');

class MultiSessionRuntime {
  constructor(dependencies = {}) {
    if (!dependencies.sessionManager) {
      throw new Error('sessionManager dependency is required.');
    }

    if (!dependencies.laravelClient || typeof dependencies.laravelClient.getEngineSessions !== 'function') {
      throw new Error('laravelClient.getEngineSessions dependency is required.');
    }

    this.mode = 'multi-session';
    this.sessionManager = dependencies.sessionManager;
    this.laravelClient = dependencies.laravelClient;
    this.logger = dependencies.logger || {
      info: () => {},
      warn: () => {},
      error: () => {},
    };
    this.setInterval = dependencies.setInterval || global.setInterval;
    this.clearInterval = dependencies.clearInterval || global.clearInterval;
    this.waitForDelay = dependencies.waitForDelay || ((ms) => new Promise((resolve) => global.setTimeout(resolve, ms)));
    this.syncIntervalMs = dependencies.syncIntervalMs || 5000;
    this.accountIdAllowlist = Array.isArray(dependencies.accountIdAllowlist)
      ? Array.from(new Set(dependencies.accountIdAllowlist.map((value) => String(value))))
      : [];
    this.recoveryInitialDelayMs = dependencies.recoveryInitialDelayMs || config.sessionRecoveryInitialDelayMs;
    this.recoveryMaxDelayMs = dependencies.recoveryMaxDelayMs || config.sessionRecoveryMaxDelayMs;
    this.recoveryCooldownMs = dependencies.recoveryCooldownMs || config.sessionRecoveryCooldownMs;
    this.recoveryMaxAttempts = dependencies.recoveryMaxAttempts || config.sessionRecoveryMaxAttempts;
    this.readyTimeoutMs = dependencies.readyTimeoutMs || config.sessionReadyTimeoutMs;
    this.heartbeatIntervalMs = dependencies.heartbeatIntervalMs || config.whatsappHeartbeatIntervalMs;

    this.timer = null;
    this.currentSyncPromise = null;
    this.started = false;
    this.startedAt = null;
    this.updatedAt = null;
    this.lastSyncError = null;
    this.lastSyncSummary = {
      fetchedCount: 0,
      filteredCount: 0,
      createdCount: 0,
      alreadyManagedCount: 0,
      stoppedCount: 0,
      removedCount: 0,
      failedCount: 0,
      allowedAccountIds: this.accountIdAllowlist,
    };
    this.isShuttingDown = false;
    this.shutdownPromise = null;
    this.recoveryStateByAccountId = new Map();
  }

  async start() {
    if (this.started) {
      return this.getSnapshot();
    }

    this.started = true;
    this.startedAt = new Date().toISOString();
    this.touch();

    this.logger.info('Starting multi-session runtime.', {
      runtime: this.mode,
      sync_interval_ms: this.syncIntervalMs,
      allowlist_count: this.accountIdAllowlist.length,
      allowlist_account_ids: this.accountIdAllowlist,
    });

    await this.syncSessions();

    if (!this.timer && !this.isShuttingDown) {
      this.timer = this.setInterval(() => {
        void this.syncSessions();
      }, this.syncIntervalMs);
    }

    return this.getSnapshot();
  }

  syncSessions() {
    if (this.isShuttingDown) {
      return Promise.resolve(this.getSnapshot());
    }

    if (this.currentSyncPromise) {
      return this.currentSyncPromise;
    }

    this.currentSyncPromise = (async () => {
      try {
        const payload = await this.laravelClient.getEngineSessions();
        const fetchedSessions = Array.isArray(payload?.data) ? payload.data : [];
        const sessions = this.filterSessions(fetchedSessions);
        const knownAccountIds = new Set();
        let createdCount = 0;
        let alreadyManagedCount = 0;
        let stoppedCount = 0;
        let removedCount = 0;
        let failedCount = 0;

        this.logger.info('Fetched sessions from Laravel for multi-session sync.', {
          runtime: this.mode,
          fetched_count: fetchedSessions.length,
          filtered_count: sessions.length,
          allowlist_account_ids: this.accountIdAllowlist,
        });

        for (const session of sessions) {
          const accountId = session?.id;

          if (accountId === undefined || accountId === null) {
            continue;
          }

          knownAccountIds.add(String(accountId));
          const snapshot = this.getManagedSnapshot(accountId);

          try {
            if (snapshot?.isReady && !this.isRecovering(accountId)) {
              await this.refreshSessionHeartbeat(accountId);
            }

            if (session.session_desired_state === 'running') {
              if (!snapshot) {
                this.logger.info('Starting managed session from sync.', {
                  runtime: this.mode,
                  accountId,
                  sessionName: session.session_name,
                });

                await this.sessionManager.start({
                  accountId,
                  sessionName: session.session_name,
                  desiredState: 'running',
                  automaticSendingEnabled: Boolean(session.automatic_sending_enabled),
                });
                createdCount += 1;
                continue;
              }

              if (this.isSessionStartBlocked(snapshot)) {
                alreadyManagedCount += 1;
                continue;
              }

              await this.sessionManager.start({
                accountId,
                sessionName: session.session_name,
                desiredState: 'running',
                automaticSendingEnabled: Boolean(session.automatic_sending_enabled),
              });
              alreadyManagedCount += 1;
              continue;
            }

            if (session.session_desired_state === 'stopped' && snapshot) {
              if (this.isSessionStoppingOrStopped(snapshot)) {
                continue;
              }

              this.logger.info('Stopping managed session from sync.', {
                runtime: this.mode,
                accountId,
                sessionName: session.session_name,
              });

              await this.sessionManager.stop(accountId);
              this.recoveryStateByAccountId.delete(String(accountId));
              stoppedCount += 1;
            }
          } catch (error) {
            failedCount += 1;
            this.logger.error('Failed to sync managed session.', {
              runtime: this.mode,
              accountId,
              sessionName: session.session_name,
              code: error.code || null,
              message: error.message,
            });
          }
        }

        for (const snapshot of this.sessionManager.getAllSnapshots()) {
          if (knownAccountIds.has(String(snapshot.accountId))) {
            continue;
          }

          try {
            this.logger.info('Removing managed session missing from Laravel sync result.', {
              runtime: this.mode,
              accountId: snapshot.accountId,
              sessionName: snapshot.sessionName,
            });

            await this.sessionManager.remove(snapshot.accountId);
            this.recoveryStateByAccountId.delete(String(snapshot.accountId));
            removedCount += 1;
          } catch (error) {
            failedCount += 1;
            this.logger.error('Failed to remove missing managed session.', {
              runtime: this.mode,
              accountId: snapshot.accountId,
              sessionName: snapshot.sessionName,
              code: error.code || null,
              message: error.message,
            });
          }
        }

        this.lastSyncError = null;
        this.lastSyncSummary = {
          fetchedCount: fetchedSessions.length,
          filteredCount: sessions.length,
          createdCount,
          alreadyManagedCount,
          stoppedCount,
          removedCount,
          failedCount,
          allowedAccountIds: this.accountIdAllowlist,
        };
        this.touch();

        return this.getSnapshot();
      } catch (error) {
        this.lastSyncError = {
          name: error.name || 'Error',
          message: error.message,
          code: error.code || null,
        };
        this.touch();

        this.logger.error('Failed to sync managed sessions from Laravel.', {
          runtime: this.mode,
          code: error.code || null,
          message: error.message,
        });

        throw error;
      } finally {
        this.currentSyncPromise = null;
      }
    })();

    return this.currentSyncPromise;
  }

  filterSessions(sessions) {
    if (!this.accountIdAllowlist.length) {
      return sessions;
    }

    const allowed = new Set(this.accountIdAllowlist);

    return sessions.filter((session) => allowed.has(String(session?.id ?? '')));
  }

  async stop() {
    if (this.shutdownPromise) {
      return this.shutdownPromise;
    }

    this.shutdownPromise = (async () => {
      this.isShuttingDown = true;

      if (this.timer) {
        this.clearInterval(this.timer);
        this.timer = null;
      }

      this.started = false;
      this.touch();

      this.logger.info('Stopping multi-session runtime.', {
        runtime: this.mode,
        managed_session_count: this.sessionManager.getAllSnapshots().length,
      });

      const result = await this.sessionManager.shutdownAll();
      this.recoveryStateByAccountId.clear();
      return result;
    })();

    try {
      return await this.shutdownPromise;
    } finally {
      this.shutdownPromise = null;
    }
  }

  async shutdown() {
    return this.stop();
  }

  getSnapshot() {
    return {
      runtime: this.mode,
      started: this.started,
      startedAt: this.startedAt,
      updatedAt: this.updatedAt,
      syncIntervalMs: this.syncIntervalMs,
      accountIdAllowlist: this.accountIdAllowlist,
      managedSessionCount: this.sessionManager.getAllSnapshots().length,
      managedSessions: this.sessionManager.getAllSnapshots(),
      lastSyncError: this.lastSyncError,
      lastSyncSummary: this.lastSyncSummary,
      hasTimer: Boolean(this.timer),
      isSyncRunning: Boolean(this.currentSyncPromise),
      isShuttingDown: this.isShuttingDown,
    };
  }

  getManagedSnapshot(accountId) {
    return this.sessionManager.getAllSnapshots().find((snapshot) => String(snapshot.accountId) === String(accountId)) || null;
  }

  getRecoveryState(accountId) {
    const key = String(accountId);

    if (!this.recoveryStateByAccountId.has(key)) {
      this.recoveryStateByAccountId.set(key, {
        attempts: 0,
        blockedUntil: null,
        lastHeartbeatAt: null,
        promise: null,
      });
    }

    return this.recoveryStateByAccountId.get(key);
  }

  isRecovering(accountId) {
    return Boolean(this.getRecoveryState(accountId).promise);
  }

  isRecoveryBlocked(accountId) {
    const blockedUntil = this.getRecoveryState(accountId).blockedUntil;

    return Number.isInteger(blockedUntil) && blockedUntil > Date.now();
  }

  isSessionStartBlocked(snapshot) {
    if (!snapshot) {
      return false;
    }

    if (this.isRecovering(snapshot.accountId) || this.isRecoveryBlocked(snapshot.accountId)) {
      return true;
    }

    return ['stopping', 'restarting'].includes(snapshot.state);
  }

  isSessionStoppingOrStopped(snapshot) {
    if (!snapshot) {
      return false;
    }

    return ['stopping', 'stopped'].includes(snapshot.state);
  }

  async handleRecoverableError(accountId, metadata = {}) {
    const recoveryState = this.getRecoveryState(accountId);

    if (recoveryState.promise) {
      return recoveryState.promise;
    }

    if (this.isRecoveryBlocked(accountId)) {
      return false;
    }

    const context = this.sessionManager.get(accountId);

    if (!context) {
      return false;
    }

    const attempt = recoveryState.attempts + 1;

    if (attempt > this.recoveryMaxAttempts) {
      return this.markRecoveryExhausted(accountId, metadata);
    }

    const delayMs = calculateRecoveryDelayMs(attempt, {
      initialDelayMs: this.recoveryInitialDelayMs,
      maxDelayMs: this.recoveryMaxDelayMs,
    });

    recoveryState.attempts = attempt;

    this.logger.warn('Recoverable session error detected.', {
      accountId,
      sessionName: context.sessionName,
      generation: context.generation,
      attempt,
      delayMs,
      stage: metadata.stage || 'unknown',
      errorMessage: getErrorMessage(metadata.error) || null,
      messageId: metadata.messageId || null,
    });

    this.logger.info('Session recovery scheduled.', {
      accountId,
      sessionName: context.sessionName,
      generation: context.generation,
      attempt,
      delayMs,
      stage: metadata.stage || 'unknown',
      messageId: metadata.messageId || null,
    });

    recoveryState.promise = (async () => {
      if (context.messageWorker && typeof context.messageWorker.stop === 'function') {
        await context.messageWorker.stop('session_recovery');
      }

      if (delayMs > 0) {
        await this.wait(delayMs);
      }

      const latestContext = this.sessionManager.get(accountId);

      if (!latestContext || latestContext.desiredState !== 'running') {
        return false;
      }

      this.logger.warn('Session recovery started.', {
        accountId,
        sessionName: latestContext.sessionName,
        generation: latestContext.generation,
        attempt,
        delayMs,
      });

      await this.sessionManager.restart(accountId, 'recoverable_session_error');

      this.logger.info('Waiting for recovered session ready.', {
        accountId,
        sessionName: latestContext.sessionName,
        generation: this.sessionManager.getSnapshot(accountId).generation,
        attempt,
      });

      const ready = await this.waitForSessionReady(accountId, this.readyTimeoutMs);

      if (!ready) {
        throw Object.assign(new Error('Recovered session did not become ready within the expected timeout.'), {
          code: 'SESSION_RECOVERY_READY_TIMEOUT',
        });
      }

      recoveryState.attempts = 0;
      recoveryState.blockedUntil = null;
      this.logger.info('Session recovery completed.', {
        accountId,
        sessionName: this.sessionManager.getSnapshot(accountId).sessionName,
        generation: this.sessionManager.getSnapshot(accountId).generation,
        attempt,
      });

      return true;
    })().catch(async (error) => {
      this.logger.error('Session recovery failed.', {
        accountId,
        sessionName: context.sessionName,
        generation: this.sessionManager.getSnapshot(accountId)?.generation ?? context.generation,
        attempt,
        delayMs,
        stage: metadata.stage || 'unknown',
        errorMessage: error?.message || String(error),
        messageId: metadata.messageId || null,
      });

      if (attempt >= this.recoveryMaxAttempts) {
        await this.markRecoveryExhausted(accountId, {
          ...metadata,
          error,
        });
      }

      return false;
    }).finally(() => {
      recoveryState.promise = null;
    });

    return recoveryState.promise;
  }

  async markRecoveryExhausted(accountId, metadata = {}) {
    const recoveryState = this.getRecoveryState(accountId);
    const context = this.sessionManager.get(accountId);
    const blockedUntil = Date.now() + this.recoveryCooldownMs;
    recoveryState.blockedUntil = blockedUntil;

    if (context?.statusClient && typeof context.statusClient.updateSessionStatus === 'function') {
      try {
        await context.statusClient.updateSessionStatus('error', {
          error_code: 'SESSION_RECOVERY_EXHAUSTED',
          error_message: 'Session recovery exhausted.',
        });
      } catch (error) {
        this.logger.warn('Failed to update session status after recovery exhaustion.', {
          accountId,
          sessionName: context.sessionName,
          code: error?.code || null,
          message: error?.message || String(error),
        });
      }
    }

    if (context) {
      try {
        await this.sessionManager.stop(accountId);
      } catch (error) {
        this.logger.warn('Failed to stop session after recovery exhaustion.', {
          accountId,
          sessionName: context.sessionName,
          code: error?.code || null,
          message: error?.message || String(error),
        });
      }
    }

    this.logger.error('Session recovery exhausted.', {
      accountId,
      sessionName: context?.sessionName || null,
      generation: this.sessionManager.getSnapshot(accountId)?.generation ?? null,
      attempt: recoveryState.attempts,
      delayMs: this.recoveryCooldownMs,
      stage: metadata.stage || 'unknown',
      errorMessage: getErrorMessage(metadata.error) || null,
      messageId: metadata.messageId || null,
    });

    return false;
  }

  async waitForSessionReady(accountId, timeoutMs) {
    const startedAt = Date.now();

    while ((Date.now() - startedAt) < timeoutMs) {
      const snapshot = this.getManagedSnapshot(accountId);

      if (snapshot?.isReady && snapshot.state === 'ready') {
        return true;
      }

      if (!snapshot || snapshot.state === 'error' || snapshot.state === 'stopped') {
        return false;
      }

      await this.wait(250);
    }

    return false;
  }

  async refreshSessionHeartbeat(accountId) {
    if (!Number.isInteger(this.heartbeatIntervalMs) || this.heartbeatIntervalMs <= 0) {
      return false;
    }

    const recoveryState = this.getRecoveryState(accountId);
    const now = Date.now();

    if (recoveryState.lastHeartbeatAt && (now - recoveryState.lastHeartbeatAt) < this.heartbeatIntervalMs) {
      return false;
    }

    const context = this.sessionManager.get(accountId);

    if (!context?.statusClient || typeof context.statusClient.updateSessionStatus !== 'function') {
      return false;
    }

    recoveryState.lastHeartbeatAt = now;

    try {
      await context.statusClient.updateSessionStatus('connected', {
        last_seen_at: new Date(now).toISOString(),
      });
      return true;
    } catch (error) {
      this.logger.warn('Failed to send managed session heartbeat.', {
        accountId,
        sessionName: context.sessionName,
        code: error?.code || null,
        message: error?.message || String(error),
      });
      return false;
    }
  }

  wait(ms) {
    return this.waitForDelay(ms);
  }

  touch() {
    this.updatedAt = new Date().toISOString();
  }
}

module.exports = {
  MultiSessionRuntime,
};