const test = require('node:test');
const assert = require('node:assert/strict');
const { MultiSessionRuntime } = require('../src/multiSessionRuntime');

const createRecoveryHarness = (options = {}) => {
  const restartCalls = [];
  const stopCalls = [];
  const statusCalls = [];
  const workerStopCalls = [];
  const waitCalls = [];
  const loggerCalls = [];
  const contexts = new Map();

  const buildContext = (accountId, overrides = {}) => ({
    accountId,
    sessionName: overrides.sessionName || ('wa_session_' + accountId),
    desiredState: overrides.desiredState || 'running',
    generation: overrides.generation || 1,
    actualState: overrides.actualState || 'ready',
    isReady: overrides.isReady ?? true,
    hasClient: overrides.hasClient ?? true,
    statusClient: overrides.statusClient || {
      async updateSessionStatus(status, extra = {}) {
        statusCalls.push({ accountId, status, extra });
      },
    },
    messageWorker: overrides.messageWorker || {
      async stop(reason) {
        workerStopCalls.push({ accountId, reason });
      },
      getSnapshot() {
        return { accountId, isRunning: true };
      },
    },
    ...overrides,
  });

  for (const context of options.contexts || [buildContext(501), buildContext(502)]) {
    contexts.set(String(context.accountId), context);
  }

  const sessionManager = {
    get(accountId) {
      return contexts.get(String(accountId)) || null;
    },
    getSnapshot(accountId) {
      const context = contexts.get(String(accountId));

      if (!context) {
        throw new Error('Missing context for ' + accountId);
      }

      return {
        accountId: context.accountId,
        sessionName: context.sessionName,
        generation: context.generation,
        state: context.actualState,
        isReady: context.isReady,
        hasClient: context.hasClient,
      };
    },
    getAllSnapshots() {
      return Array.from(contexts.values()).map((context) => ({
        accountId: context.accountId,
        sessionName: context.sessionName,
        generation: context.generation,
        state: context.actualState,
        isReady: context.isReady,
        hasClient: context.hasClient,
      }));
    },
    async start() {},
    async remove() { return true; },
    async shutdownAll() { return { total: contexts.size, succeeded: contexts.size, failed: 0, results: [] }; },
    async stop(accountId) {
      stopCalls.push(accountId);
      const context = contexts.get(String(accountId));

      if (context) {
        context.actualState = 'stopped';
        context.isReady = false;
        context.hasClient = false;
      }
    },
    async restart(accountId) {
      restartCalls.push(accountId);
      const context = contexts.get(String(accountId));

      if (!context) {
        throw new Error('Missing context');
      }

      if (typeof options.onRestart === 'function') {
        await options.onRestart(context, { restartCalls, stopCalls, statusCalls, workerStopCalls });
        return;
      }

      context.generation += 1;
      context.actualState = 'ready';
      context.isReady = true;
      context.hasClient = true;
    },
  };

  const runtime = new MultiSessionRuntime({
    sessionManager,
    laravelClient: {
      async getEngineSessions() {
        return { success: true, data: options.sessions || [] };
      },
    },
    logger: {
      info: (...args) => loggerCalls.push({ level: 'info', args }),
      warn: (...args) => loggerCalls.push({ level: 'warn', args }),
      error: (...args) => loggerCalls.push({ level: 'error', args }),
    },
    waitForDelay: async (ms) => {
      waitCalls.push(ms);
    },
    recoveryInitialDelayMs: options.recoveryInitialDelayMs || 5000,
    recoveryMaxDelayMs: options.recoveryMaxDelayMs || 60000,
    recoveryCooldownMs: options.recoveryCooldownMs || 120000,
    recoveryMaxAttempts: options.recoveryMaxAttempts || 4,
    readyTimeoutMs: options.readyTimeoutMs || 1000,
    heartbeatIntervalMs: options.heartbeatIntervalMs || 30000,
  });

  return {
    runtime,
    contexts,
    restartCalls,
    stopCalls,
    statusCalls,
    workerStopCalls,
    waitCalls,
    loggerCalls,
  };
};

test('concurrent recoverable errors for the same account trigger one recovery only', async () => {
  let releaseStop;
  let harness;

  const slowWorker = {
    async stop(reason) {
      harness.workerStopCalls.push({ accountId: 501, reason });
      await new Promise((resolve) => {
        releaseStop = resolve;
      });
    },
    getSnapshot() {
      return { accountId: 501, isRunning: true };
    },
  };

  harness = createRecoveryHarness({
    contexts: [
      {
        accountId: 501,
        sessionName: 'wa_session_501',
        desiredState: 'running',
        generation: 1,
        actualState: 'ready',
        isReady: true,
        hasClient: true,
        messageWorker: slowWorker,
      },
      {
        accountId: 502,
        sessionName: 'wa_session_502',
        desiredState: 'running',
        generation: 1,
        actualState: 'ready',
        isReady: true,
        hasClient: true,
      },
    ],
  });

  const first = harness.runtime.handleRecoverableError(501, {
    stage: 'send_message',
    error: new Error('Attempted to use detached Frame'),
    messageId: 88,
  });
  const second = harness.runtime.handleRecoverableError(501, {
    stage: 'send_message',
    error: new Error('Attempted to use detached Frame'),
    messageId: 88,
  });

  await Promise.resolve();
  assert.equal(harness.runtime.isRecovering(501), true);
  assert.deepEqual(harness.workerStopCalls, [{ accountId: 501, reason: 'session_recovery' }]);
  assert.deepEqual(harness.restartCalls, []);
  assert.deepEqual(harness.waitCalls, []);

  releaseStop();
  const [firstResult, secondResult] = await Promise.all([first, second]);

  assert.equal(firstResult, true);
  assert.equal(secondResult, true);
  assert.deepEqual(harness.restartCalls, [501]);
  assert.deepEqual(harness.waitCalls, [5000]);
  assert.equal(harness.runtime.isRecovering(501), false);
});

test('recovery restarts only the affected account and leaves the second account untouched', async () => {
  const harness = createRecoveryHarness();

  await harness.runtime.handleRecoverableError(501, {
    stage: 'resolve_number',
    error: new Error('Execution context was destroyed'),
    messageId: 91,
  });

  assert.deepEqual(harness.restartCalls, [501]);
  assert.deepEqual(harness.stopCalls, []);
  assert.equal(harness.contexts.get('501').generation, 2);
  assert.equal(harness.contexts.get('502').generation, 1);
});

test('recovery exhaustion updates status stops only the affected account and blocks immediate retries', async () => {
  const harness = createRecoveryHarness({
    recoveryMaxAttempts: 2,
    recoveryCooldownMs: 120000,
    onRestart: async () => {
      throw Object.assign(new Error('restart failed'), { code: 'RESTART_FAILED' });
    },
  });

  const first = await harness.runtime.handleRecoverableError(501, {
    stage: 'send_message',
    error: new Error('Target closed'),
    messageId: 100,
  });
  const second = await harness.runtime.handleRecoverableError(501, {
    stage: 'send_message',
    error: new Error('Target closed'),
    messageId: 100,
  });
  const third = await harness.runtime.handleRecoverableError(501, {
    stage: 'send_message',
    error: new Error('Target closed'),
    messageId: 100,
  });

  assert.equal(first, false);
  assert.equal(second, false);
  assert.equal(third, false);
  assert.equal(harness.runtime.isRecoveryBlocked(501), true);
  assert.deepEqual(harness.waitCalls, [5000, 15000]);
  assert.deepEqual(harness.restartCalls, [501, 501]);
  assert.deepEqual(harness.stopCalls, [501]);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 501 && entry.status === 'error' && entry.extra.error_code === 'SESSION_RECOVERY_EXHAUSTED'), true);
  assert.equal(harness.contexts.get('502').generation, 1);
});

test('ready sessions send a heartbeat only for the matching account and respect the interval window', async () => {
  const harness = createRecoveryHarness({
    sessions: [{ id: 501, session_name: 'wa_session_501', session_desired_state: 'running' }],
    heartbeatIntervalMs: 30000,
  });

  await harness.runtime.syncSessions();
  await harness.runtime.syncSessions();

  const heartbeatCalls = harness.statusCalls.filter((entry) => entry.status === 'connected');

  assert.equal(heartbeatCalls.length, 1);
  assert.equal(heartbeatCalls[0].accountId, 501);
  assert.equal(typeof heartbeatCalls[0].extra.last_seen_at, 'string');
});