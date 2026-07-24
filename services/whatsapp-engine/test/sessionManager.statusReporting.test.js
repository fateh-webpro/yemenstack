const test = require('node:test');
const assert = require('node:assert/strict');
const { SessionManager } = require('../src/sessionManager');

const flushAsync = async (times = 2) => {
  for (let index = 0; index < times; index += 1) {
    await Promise.resolve();
  }
};

const createHarness = (options = {}) => {
  const callbacks = new Map();
  const workerCalls = [];
  const statusCalls = [];
  const logCalls = [];
  const destroyCalls = [];
  const timers = [];
  const clearedTimers = [];
  const statusFailures = new Set(options.statusFailures || []);

  const manager = new SessionManager({
    readinessTimeoutMs: options.readinessTimeoutMs || 1000,
    setTimeout(callback, ms) {
      const timer = { callback, ms, cleared: false };
      timers.push(timer);
      return timer;
    },
    clearTimeout(timer) {
      if (timer && !timer.cleared) {
        timer.cleared = true;
        clearedTimers.push(timer);
      }
    },
    createClient: async (descriptor, sessionCallbacks) => {
      callbacks.set(String(descriptor.accountId), sessionCallbacks);
      return {
        async initialize() {},
        async destroy() {
          destroyCalls.push({ accountId: descriptor.accountId, generation: descriptor.generation });
        },
        async sendMessage() {
          return { ok: true };
        },
      };
    },
    createStatusClient: (descriptor) => ({
      async updateSessionStatus(status, extra = {}) {
        statusCalls.push({ accountId: descriptor.accountId, generation: descriptor.generation, status, extra });

        if (statusFailures.has(status)) {
          const error = new Error(`status update failed: ${status}`);
          error.code = 'STATUS_UPDATE_FAILED';
          throw error;
        }
      },
    }),
    createMessageWorker: (descriptor, helpers) => ({
      async start() {
        workerCalls.push({ type: 'start', accountId: descriptor.accountId, generation: helpers.getGeneration() });
      },
      async stop(reason) {
        workerCalls.push({ type: 'stop', accountId: descriptor.accountId, generation: helpers.getGeneration(), reason });
      },
      getSnapshot() {
        return { accountId: descriptor.accountId, isRunning: true };
      },
    }),
    logger: {
      info: (...args) => logCalls.push({ level: 'info', args }),
      warn: (...args) => logCalls.push({ level: 'warn', args }),
      error: (...args) => logCalls.push({ level: 'error', args }),
    },
  });

  return {
    manager,
    callbacks,
    workerCalls,
    statusCalls,
    logCalls,
    destroyCalls,
    timers,
    clearedTimers,
    emit(accountId, eventName, ...payload) {
      callbacks.get(String(accountId))[eventName](...payload);
    },
    async fireTimer(index) {
      const timer = timers[index];
      if (!timer || timer.cleared) {
        return;
      }

      timer.cleared = true;
      clearedTimers.push(timer);
      await timer.callback();
      await flushAsync(4);
    },
    getActiveTimers(accountId) {
      const snapshot = manager.getSnapshot(accountId);
      return { waitingForReady: snapshot.waitingForReady, readinessDeadlineAt: snapshot.readinessDeadlineAt };
    },
  };
};

test('managed session lifecycle reports connecting authenticated qr_required connected disconnected and error per account', async () => {
  const harness = createHarness();

  await harness.manager.start({ accountId: 701, sessionName: 'wa_session_701', desiredState: 'running' });
  await harness.manager.start({ accountId: 702, sessionName: 'wa_session_702', desiredState: 'running' });

  harness.emit(701, 'onQr', 'RAW-QR-701');
  harness.emit(701, 'onAuthenticated');
  harness.emit(701, 'onReady');
  harness.emit(702, 'onError', Object.assign(new Error('socket closed'), { code: 'SOCKET_CLOSED' }));
  harness.emit(701, 'onDisconnected', 'network');
  await flushAsync(4);

  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 701 && entry.status === 'connecting'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 701 && entry.status === 'qr_required'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 701 && entry.status === 'authenticated'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 701 && entry.status === 'connected'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 701 && entry.status === 'disconnected'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 702 && entry.status === 'error'), true);
  assert.equal(harness.statusCalls.some((entry) => JSON.stringify(entry.extra).includes('RAW-QR-701')), false);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 701), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'stop' && entry.accountId === 701), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'stop' && entry.accountId === 702), true);
});

test('ready before timeout cancels readiness timer and starts the worker', async () => {
  const harness = createHarness({ readinessTimeoutMs: 5000 });

  await harness.manager.start({ accountId: 703, sessionName: 'wa_session_703', desiredState: 'running' });
  const beforeReady = harness.manager.getSnapshot(703);
  assert.equal(beforeReady.waitingForReady, true);
  assert.equal(beforeReady.readinessDeadlineAt !== null, true);

  harness.emit(703, 'onReady');
  await flushAsync(4);

  const snapshot = harness.manager.getSnapshot(703);
  assert.equal(snapshot.isReady, true);
  assert.equal(snapshot.waitingForReady, false);
  assert.equal(snapshot.readinessDeadlineAt, null);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 703), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 703 && entry.status === 'connected'), true);
});

test('authenticated session that never reaches ready times out safely and destroys the client', async () => {
  const harness = createHarness({ readinessTimeoutMs: 1500 });

  await harness.manager.start({ accountId: 704, sessionName: 'wa_session_704', desiredState: 'running' });
  harness.emit(704, 'onAuthenticated');
  await flushAsync(3);
  await harness.fireTimer(0);

  const snapshot = harness.manager.getSnapshot(704);
  assert.equal(snapshot.isReady, false);
  assert.equal(snapshot.state, 'error');
  assert.equal(snapshot.waitingForReady, false);
  assert.equal(snapshot.hasClient, false);
  assert.equal(snapshot.lastError.code, 'MANAGED_SESSION_READY_TIMEOUT');
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 704 && entry.status === 'error' && entry.extra.error_code === 'MANAGED_SESSION_READY_TIMEOUT'), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 704), false);
  assert.equal(harness.destroyCalls.some((entry) => entry.accountId === 704), true);
});

test('old generation timeout and callbacks do not affect the new generation', async () => {
  const harness = createHarness({ readinessTimeoutMs: 1500 });

  await harness.manager.start({ accountId: 705, sessionName: 'wa_session_705', desiredState: 'running' });
  const oldCallbacks = harness.callbacks.get('705');
  const oldTimer = harness.timers[0];

  await harness.manager.restart(705, 'refresh');
  const newCallbacks = harness.callbacks.get('705');
  newCallbacks.onReady();
  await flushAsync(4);

  if (!oldTimer.cleared) {
    oldTimer.cleared = true;
    await oldTimer.callback();
  }

  oldCallbacks.onReady();
  oldCallbacks.onDisconnected('stale');
  await flushAsync(4);

  const snapshot = harness.manager.getSnapshot(705);
  const errorCalls = harness.statusCalls.filter((entry) => entry.accountId === 705 && entry.status === 'error');
  const connectedCalls = harness.statusCalls.filter((entry) => entry.accountId === 705 && entry.status === 'connected');

  assert.equal(snapshot.isReady, true);
  assert.equal(errorCalls.length, 0);
  assert.equal(connectedCalls.length, 1);
});

test('stop disconnected and error cancel readiness timers', async () => {
  const harness = createHarness({ readinessTimeoutMs: 1500 });

  await harness.manager.start({ accountId: 706, sessionName: 'wa_session_706', desiredState: 'running' });
  await harness.manager.stop(706);
  assert.equal(harness.manager.getSnapshot(706).waitingForReady, false);

  await harness.manager.start({ accountId: 707, sessionName: 'wa_session_707', desiredState: 'running' });
  harness.emit(707, 'onDisconnected', 'network');
  await flushAsync(3);
  assert.equal(harness.manager.getSnapshot(707).waitingForReady, false);

  await harness.manager.start({ accountId: 708, sessionName: 'wa_session_708', desiredState: 'running' });
  harness.emit(708, 'onError', new Error('boom'));
  await flushAsync(3);
  assert.equal(harness.manager.getSnapshot(708).waitingForReady, false);
});

test('two sessions maintain independent readiness timers', async () => {
  const harness = createHarness({ readinessTimeoutMs: 2000 });

  await harness.manager.start({ accountId: 709, sessionName: 'wa_session_709', desiredState: 'running' });
  await harness.manager.start({ accountId: 710, sessionName: 'wa_session_710', desiredState: 'running' });

  const first = harness.manager.getSnapshot(709);
  const second = harness.manager.getSnapshot(710);

  assert.equal(first.waitingForReady, true);
  assert.equal(second.waitingForReady, true);
  assert.equal(harness.timers.length, 2);
  assert.equal(harness.timers[0] !== harness.timers[1], true);
});

test('status update failures do not prevent ready from starting the worker', async () => {
  const harness = createHarness({ statusFailures: ['connected'] });

  await harness.manager.start({ accountId: 711, sessionName: 'wa_session_711', desiredState: 'running' });
  harness.emit(711, 'onReady');
  await flushAsync(4);

  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 711 && entry.status === 'connected'), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 711), true);
  assert.equal(harness.logCalls.some((entry) => entry.level === 'warn' && String(entry.args[0]).includes('Failed to update managed session status.')), true);
});

test('session snapshots stay free of tokens qr raw payloads and client objects', async () => {
  const harness = createHarness();

  await harness.manager.start({ accountId: 712, sessionName: 'wa_session_712', desiredState: 'running' });
  const snapshot = harness.manager.getSnapshot(712);

  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'token'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'qr'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'client'), false);
  assert.equal(snapshot.hasStatusClient, true);
});