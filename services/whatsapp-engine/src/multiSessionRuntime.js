class MultiSessionRuntime {
  constructor(dependencies = {}) {
    if (!dependencies.sessionManager) {
      throw new Error('sessionManager dependency is required.');
    }

    if (!dependencies.laravelClient || typeof dependencies.laravelClient.getEngineSessions !== 'function') {
      throw new Error('laravelClient.getEngineSessions dependency is required.');
    }

    this.sessionManager = dependencies.sessionManager;
    this.laravelClient = dependencies.laravelClient;
    this.logger = dependencies.logger || {
      info: () => {},
      warn: () => {},
      error: () => {},
    };
    this.setInterval = dependencies.setInterval || global.setInterval;
    this.clearInterval = dependencies.clearInterval || global.clearInterval;
    this.syncIntervalMs = dependencies.syncIntervalMs || 5000;

    this.timer = null;
    this.currentSyncPromise = null;
    this.started = false;
    this.startedAt = null;
    this.updatedAt = null;
    this.lastSyncError = null;
  }

  async start() {
    if (this.started) {
      return this.getSnapshot();
    }

    this.started = true;
    this.startedAt = new Date().toISOString();
    this.touch();

    await this.syncSessions();

    if (!this.timer) {
      this.timer = this.setInterval(() => {
        void this.syncSessions();
      }, this.syncIntervalMs);
    }

    return this.getSnapshot();
  }

  syncSessions() {
    if (this.currentSyncPromise) {
      return this.currentSyncPromise;
    }

    this.currentSyncPromise = (async () => {
      try {
        const payload = await this.laravelClient.getEngineSessions();
        const sessions = Array.isArray(payload?.data) ? payload.data : [];
        const knownAccountIds = new Set();

        for (const session of sessions) {
          const accountId = session?.id;

          if (accountId === undefined || accountId === null) {
            continue;
          }

          knownAccountIds.add(String(accountId));

          try {
            if (session.session_desired_state === 'running') {
              await this.sessionManager.start({
                accountId,
                sessionName: session.session_name,
                desiredState: 'running',
              });
              continue;
            }

            if (session.session_desired_state === 'stopped' && this.sessionManager.has(accountId)) {
              await this.sessionManager.stop(accountId);
            }
          } catch (error) {
            this.logger.error('Failed to sync managed session.', {
              accountId,
              message: error.message,
            });
          }
        }

        for (const snapshot of this.sessionManager.getAllSnapshots()) {
          if (knownAccountIds.has(String(snapshot.accountId))) {
            continue;
          }

          try {
            await this.sessionManager.remove(snapshot.accountId);
          } catch (error) {
            this.logger.error('Failed to remove missing managed session.', {
              accountId: snapshot.accountId,
              message: error.message,
            });
          }
        }

        this.lastSyncError = null;
        this.touch();

        return this.getSnapshot();
      } catch (error) {
        this.lastSyncError = {
          name: error.name || 'Error',
          message: error.message,
        };
        this.touch();

        this.logger.error('Failed to sync managed sessions from Laravel.', {
          message: error.message,
        });

        throw error;
      } finally {
        this.currentSyncPromise = null;
      }
    })();

    return this.currentSyncPromise;
  }

  async stop() {
    if (this.timer) {
      this.clearInterval(this.timer);
      this.timer = null;
    }

    this.started = false;
    this.touch();

    return this.sessionManager.shutdownAll();
  }

  async shutdown() {
    return this.stop();
  }

  getSnapshot() {
    return {
      started: this.started,
      startedAt: this.startedAt,
      updatedAt: this.updatedAt,
      syncIntervalMs: this.syncIntervalMs,
      managedSessions: this.sessionManager.getAllSnapshots(),
      lastSyncError: this.lastSyncError,
      hasTimer: Boolean(this.timer),
      isSyncRunning: Boolean(this.currentSyncPromise),
    };
  }

  touch() {
    this.updatedAt = new Date().toISOString();
  }
}

module.exports = {
  MultiSessionRuntime,
};