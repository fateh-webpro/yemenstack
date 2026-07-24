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
    this.syncIntervalMs = dependencies.syncIntervalMs || 5000;
    this.accountIdAllowlist = Array.isArray(dependencies.accountIdAllowlist)
      ? Array.from(new Set(dependencies.accountIdAllowlist.map((value) => String(value))))
      : [];

    this.timer = null;
    this.currentSyncPromise = null;
    this.started = false;
    this.startedAt = null;
    this.updatedAt = null;
    this.lastSyncError = null;
    this.lastSyncSummary = {
      fetchedCount: 0,
      filteredCount: 0,
      startedCount: 0,
      stoppedCount: 0,
      removedCount: 0,
      allowedAccountIds: this.accountIdAllowlist,
    };
    this.isShuttingDown = false;
    this.shutdownPromise = null;
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
        let startedCount = 0;
        let stoppedCount = 0;
        let removedCount = 0;

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

          try {
            if (session.session_desired_state === 'running') {
              this.logger.info('Starting managed session from sync.', {
                runtime: this.mode,
                accountId,
                sessionName: session.session_name,
              });

              await this.sessionManager.start({
                accountId,
                sessionName: session.session_name,
                desiredState: 'running',
              });
              startedCount += 1;
              continue;
            }

            if (session.session_desired_state === 'stopped' && this.sessionManager.has(accountId)) {
              this.logger.info('Stopping managed session from sync.', {
                runtime: this.mode,
                accountId,
                sessionName: session.session_name,
              });

              await this.sessionManager.stop(accountId);
              stoppedCount += 1;
            }
          } catch (error) {
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
            removedCount += 1;
          } catch (error) {
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
          startedCount,
          stoppedCount,
          removedCount,
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

      return this.sessionManager.shutdownAll();
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

  touch() {
    this.updatedAt = new Date().toISOString();
  }
}

module.exports = {
  MultiSessionRuntime,
};