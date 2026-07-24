const test = require('node:test');
const assert = require('node:assert/strict');
const { MultiSessionRuntime } = require('../src/multiSessionRuntime');

const createHarness = (sessionsFactory, options = {}) => {
  const started = [];
  const stopped = [];
  const removed = [];
  const loggerCalls = [];
  const active = new Map();
  const intervals = [];
  const clearedIntervals = [];
  let shutdownAllCalls = 0;

  const sessionManager = {
    has(accountId) {
      return active.has(String(accountId));
    },
    async start(descriptor) {
      started.push(descriptor);
      active.set(String(descriptor.accountId), {
        accountId: descriptor.accountId,
        sessionName: descriptor.sessionName,
        state: 'running',
        desiredState: 'running',
        generation: 1,
        isReady: false,
        hasClient: true,
        hasMessageWorker: true,
        messageWorker: {
          isRunning: true,
          isCycleRunning: false,
          processedCount: 0,
          sentCount: 0,
          failedCount: 0,
          lastError: null,
        },
        lastError: null,
        createdAt: 't1',
        updatedAt: 't1',
      });
    },
    async stop(accountId) {
      stopped.push(String(accountId));
      const current = active.get(String(accountId));
      if (current) {
        current.state = 'stopped';
        current.desiredState = 'stopped';
        current.hasClient = false;
      }
    },
    async remove(accountId) {
      removed.push(String(accountId));
      active.delete(String(accountId));
      return true;
    },
    async shutdownAll() {
      shutdownAllCalls += 1;
      const total = active.size;
      active.clear();
      return { total, succeeded: total, failed: 0, results: [] };
    },
    getAllSnapshots() {
      return Array.from(active.values());
    },
  };

  const runtime = new MultiSessionRuntime({
    sessionManager,
    laravelClient: {
      async getEngineSessions() {
        return {
          success: true,
          data: await sessionsFactory(),
        };
      },
    },
    logger: {
      info: (...args) => loggerCalls.push({ level: 'info', args }),
      warn: (...args) => loggerCalls.push({ level: 'warn', args }),
      error: (...args) => loggerCalls.push({ level: 'error', args }),
    },
    setInterval(callback, ms) {
      const timer = { callback, ms };
      intervals.push(timer);
      return timer;
    },
    clearInterval(timer) {
      clearedIntervals.push(timer);
    },
    syncIntervalMs: 1500,
    accountIdAllowlist: options.accountIdAllowlist || [],
  });

  return {
    runtime,
    started,
    stopped,
    removed,
    loggerCalls,
    intervals,
    clearedIntervals,
    active,
    getShutdownAllCalls() {
      return shutdownAllCalls;
    },
  };
};

test('runtime starts with no sessions and creates one timer', async () => {
  const harness = createHarness(async () => []);

  const snapshot = await harness.runtime.start();

  assert.equal(snapshot.runtime, 'multi-session');
  assert.equal(snapshot.managedSessions.length, 0);
  assert.equal(harness.intervals.length, 1);
});

test('sync starts running sessions and stops stopped sessions', async () => {
  const harness = createHarness(async () => [
    { id: 1, session_name: 'wa_one', session_desired_state: 'running' },
    { id: 2, session_name: 'wa_two', session_desired_state: 'stopped' },
  ]);

  harness.active.set('2', {
    accountId: 2,
    sessionName: 'wa_two',
    state: 'running',
    desiredState: 'running',
    generation: 1,
    isReady: false,
    hasClient: true,
    hasMessageWorker: true,
    messageWorker: null,
    lastError: null,
    createdAt: 't1',
    updatedAt: 't1',
  });

  await harness.runtime.syncSessions();

  assert.equal(harness.started.length, 1);
  assert.equal(harness.started[0].accountId, 1);
  assert.deepEqual(harness.stopped, ['2']);
  assert.equal(harness.runtime.getSnapshot().lastSyncSummary.createdCount, 1);
  assert.equal(harness.runtime.getSnapshot().lastSyncSummary.stoppedCount, 1);
});

test('sync counts already managed running sessions without logging a new start each cycle', async () => {
  const harness = createHarness(async () => [
    { id: 1, session_name: 'wa_one', session_desired_state: 'running' },
  ]);

  await harness.runtime.syncSessions();
  const firstStartLogs = harness.loggerCalls.filter((entry) => entry.level === 'info' && entry.args[0] === 'Starting managed session from sync.');

  await harness.runtime.syncSessions();
  const secondStartLogs = harness.loggerCalls.filter((entry) => entry.level === 'info' && entry.args[0] === 'Starting managed session from sync.');
  const summary = harness.runtime.getSnapshot().lastSyncSummary;

  assert.equal(firstStartLogs.length, 1);
  assert.equal(secondStartLogs.length, 1);
  assert.equal(summary.createdCount, 0);
  assert.equal(summary.alreadyManagedCount, 1);
});

test('allowlist filters sessions down to the requested account ids only', async () => {
  const harness = createHarness(async () => [
    { id: 5, session_name: 'wa_five', session_desired_state: 'running' },
    { id: 8, session_name: 'wa_eight', session_desired_state: 'running' },
    { id: 9, session_name: 'wa_nine', session_desired_state: 'running' },
  ], {
    accountIdAllowlist: ['5', '8'],
  });

  await harness.runtime.syncSessions();

  assert.deepEqual(harness.started.map((entry) => entry.accountId), [5, 8]);
  assert.equal(harness.started.some((entry) => entry.accountId === 9), false);
  assert.equal(harness.runtime.getSnapshot().lastSyncSummary.fetchedCount, 3);
  assert.equal(harness.runtime.getSnapshot().lastSyncSummary.filteredCount, 2);
});

test('sync removes sessions that no longer exist in Laravel', async () => {
  const harness = createHarness(async () => []);

  harness.active.set('3', {
    accountId: 3,
    sessionName: 'wa_three',
    state: 'running',
    desiredState: 'running',
    generation: 1,
    isReady: false,
    hasClient: true,
    hasMessageWorker: true,
    messageWorker: null,
    lastError: null,
    createdAt: 't1',
    updatedAt: 't1',
  });

  await harness.runtime.syncSessions();

  assert.deepEqual(harness.removed, ['3']);
  assert.equal(harness.runtime.getSnapshot().lastSyncSummary.removedCount, 1);
});

test('session sync errors are isolated and do not stop other sessions', async () => {
  const harness = createHarness(async () => [
    { id: 4, session_name: 'wa_four', session_desired_state: 'running' },
    { id: 5, session_name: 'wa_five', session_desired_state: 'running' },
  ]);

  harness.runtime.sessionManager.start = async (descriptor) => {
    if (descriptor.accountId === 4) {
      throw new Error('start failed');
    }

    harness.started.push(descriptor);
    harness.active.set(String(descriptor.accountId), {
      accountId: descriptor.accountId,
      sessionName: descriptor.sessionName,
      state: 'running',
      desiredState: 'running',
      generation: 1,
      isReady: false,
      hasClient: true,
      hasMessageWorker: true,
      messageWorker: null,
      lastError: null,
      createdAt: 't1',
      updatedAt: 't1',
    });
  };

  await harness.runtime.syncSessions();

  assert.equal(harness.started.length, 1);
  assert.equal(harness.started[0].accountId, 5);
  assert.equal(harness.loggerCalls.some((entry) => entry.level === 'error'), true);
  assert.equal(harness.runtime.getSnapshot().lastSyncSummary.failedCount, 1);
});

test('sync does not overlap and returns the same promise while running', async () => {
  let release;
  const harness = createHarness(() => new Promise((resolve) => {
    release = () => resolve([]);
  }));

  const first = harness.runtime.syncSessions();
  const second = harness.runtime.syncSessions();

  assert.equal(first, second);

  release();
  await first;
});

test('snapshot does not expose tokens qr raw payloads or client objects', async () => {
  const harness = createHarness(async () => [
    { id: 6, session_name: 'wa_six', session_desired_state: 'running' },
  ], {
    accountIdAllowlist: ['6'],
  });

  await harness.runtime.start();
  const snapshot = harness.runtime.getSnapshot();

  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'token'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'qr'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'client'), false);
  assert.deepEqual(snapshot.accountIdAllowlist, ['6']);
});

test('shutdown is idempotent and clears timer only once', async () => {
  const harness = createHarness(async () => [
    { id: 6, session_name: 'wa_six', session_desired_state: 'running' },
  ]);

  await harness.runtime.start();
  const first = harness.runtime.shutdown();
  const second = harness.runtime.shutdown();
  const result = await first;
  await second;

  assert.equal(result.total, 1);
  assert.equal(harness.runtime.getSnapshot().hasTimer, false);
  assert.equal(harness.runtime.getSnapshot().isShuttingDown, true);
  assert.equal(harness.clearedIntervals.length, 1);
  assert.equal(harness.getShutdownAllCalls(), 1);
});