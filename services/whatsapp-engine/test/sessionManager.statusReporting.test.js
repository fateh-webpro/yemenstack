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
  const deferredDestroyResolvers = new Map();
  const destroyStartedAccounts = new Set();
  const destroyWaiters = new Map();

  const markDestroyStarted = (accountId) => {
    const key = String(accountId);
    destroyStartedAccounts.add(key);

    if (destroyWaiters.has(key)) {
      destroyWaiters.get(key)();
      destroyWaiters.delete(key);
    }
  };

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
          markDestroyStarted(descriptor.accountId);

          if (options.slowDestroyFor === descriptor.accountId) {
            await new Promise((resolve) => {
              deferredDestroyResolvers.set(String(descriptor.accountId), resolve);
            });
          }

          if (options.failDestroyFor === descriptor.accountId) {
            const error = new Error(`Destroy failed for ${descriptor.accountId}`);
            error.code = 'DESTROY_FAILED';
            throw error;
          }
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
      async storeSessionQr(qr, extra = {}) {
        statusCalls.push({ accountId: descriptor.accountId, generation: descriptor.generation, status: 'store_qr', extra: { ...extra, qr } });

        if (statusFailures.has('store_qr')) {
          const error = new Error('qr store failed');
          error.code = 'QR_STORE_FAILED';
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
    async waitForDestroy(accountId) {
      const key = String(accountId);

      if (destroyStartedAccounts.has(key)) {
        return;
      }

      await new Promise((resolve) => {
        destroyWaiters.set(key, resolve);
      });
    },
    resolveDestroy(accountId) {
      const resolve = deferredDestroyResolvers.get(String(accountId));
      if (resolve) {
        deferredDestroyResolvers.delete(String(accountId));
        resolve();
      }
    },
  };
};

test('managed session lifecycle reports connecting qr_required authenticated connected disconnected and error per account', async () => {
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
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 701 && entry.status === 'store_qr'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 701 && entry.status === 'qr_required'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 701 && entry.status === 'authenticated'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 701 && entry.status === 'connected'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 701 && entry.status === 'disconnected'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 702 && entry.status === 'error'), true);
  assert.equal(harness.logCalls.some((entry) => JSON.stringify(entry).includes('RAW-QR-701')), false);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 701), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'stop' && entry.accountId === 701), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'stop' && entry.accountId === 702), true);
});

test('start and qr do not arm readiness timer and authenticated arms it only once', async () => {
  const harness = createHarness({ readinessTimeoutMs: 5000 });

  await harness.manager.start({ accountId: 703, sessionName: 'wa_session_703', desiredState: 'running' });
  let snapshot = harness.manager.getSnapshot(703);

  assert.equal(snapshot.state, 'starting');
  assert.equal(snapshot.waitingForReady, false);
  assert.equal(snapshot.readinessDeadlineAt, null);
  assert.equal(harness.timers.length, 0);

  harness.emit(703, 'onQr', 'RAW-QR-703');
  await flushAsync(3);
  snapshot = harness.manager.getSnapshot(703);

  assert.equal(snapshot.state, 'waiting_for_qr');
  assert.equal(snapshot.waitingForReady, false);
  assert.equal(harness.timers.length, 0);

  harness.emit(703, 'onAuthenticated');
  await flushAsync(3);
  snapshot = harness.manager.getSnapshot(703);

  assert.equal(snapshot.state, 'authenticated');
  assert.equal(snapshot.waitingForReady, true);
  assert.equal(snapshot.readinessDeadlineAt !== null, true);
  assert.equal(harness.timers.length, 1);

  harness.emit(703, 'onAuthenticated');
  await flushAsync(3);
  assert.equal(harness.timers.length, 1);
});

test('ready before timeout cancels readiness timer and starts the worker', async () => {
  const harness = createHarness({ readinessTimeoutMs: 5000 });

  await harness.manager.start({ accountId: 704, sessionName: 'wa_session_704', desiredState: 'running' });
  harness.emit(704, 'onAuthenticated');
  await flushAsync(3);
  harness.emit(704, 'onReady');
  await flushAsync(4);

  const snapshot = harness.manager.getSnapshot(704);
  assert.equal(snapshot.isReady, true);
  assert.equal(snapshot.state, 'ready');
  assert.equal(snapshot.waitingForReady, false);
  assert.equal(snapshot.readinessDeadlineAt, null);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 704), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 704 && entry.status === 'connected'), true);
});

test('waiting on qr alone never triggers readiness timeout', async () => {
  const harness = createHarness({ readinessTimeoutMs: 1500 });

  await harness.manager.start({ accountId: 705, sessionName: 'wa_session_705', desiredState: 'running' });
  harness.emit(705, 'onQr', 'RAW-QR-705');
  await flushAsync(3);

  assert.equal(harness.timers.length, 0);
  assert.equal(harness.manager.getSnapshot(705).state, 'waiting_for_qr');
  assert.equal(harness.manager.getSnapshot(705).waitingForReady, false);
});

test('authenticated session that never reaches ready times out safely and destroys the client', async () => {
  const harness = createHarness({ readinessTimeoutMs: 1500 });

  await harness.manager.start({ accountId: 706, sessionName: 'wa_session_706', desiredState: 'running' });
  harness.emit(706, 'onAuthenticated');
  await flushAsync(3);
  await harness.fireTimer(0);

  const snapshot = harness.manager.getSnapshot(706);
  assert.equal(snapshot.isReady, false);
  assert.equal(snapshot.state, 'stopped');
  assert.equal(snapshot.desiredState, 'running');
  assert.equal(snapshot.waitingForReady, false);
  assert.equal(snapshot.hasClient, false);
  assert.equal(snapshot.lastError.code, 'MANAGED_SESSION_READY_TIMEOUT');
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 706 && entry.status === 'error' && entry.extra.error_code === 'MANAGED_SESSION_READY_TIMEOUT'), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 706), false);
  assert.equal(harness.destroyCalls.some((entry) => entry.accountId === 706), true);
});

test('timeout waits for destroy before allowing a new generation to start', async () => {
  const harness = createHarness({ readinessTimeoutMs: 1000, slowDestroyFor: 707 });

  await harness.manager.start({ accountId: 707, sessionName: 'wa_session_707', desiredState: 'running' });
  harness.emit(707, 'onAuthenticated');
  await flushAsync(3);

  const timeoutPromise = harness.fireTimer(0);
  await harness.waitForDestroy(707);

  const startDuringDestroy = harness.manager.start({ accountId: 707, sessionName: 'wa_session_707', desiredState: 'running' });
  await flushAsync(3);

  assert.equal(harness.manager.getSnapshot(707).state, 'stopping');
  assert.equal(harness.manager.getSnapshot(707).hasClient, true);

  harness.resolveDestroy(707);
  await timeoutPromise;
  await startDuringDestroy;
  await flushAsync(3);

  const restarted = await harness.manager.start({ accountId: 707, sessionName: 'wa_session_707', desiredState: 'running' });

  assert.equal(restarted.generation, 2);
  assert.equal(restarted.state, 'starting');
});

test('old generation timeout and callbacks do not affect the new generation', async () => {
  const harness = createHarness({ readinessTimeoutMs: 1500 });

  await harness.manager.start({ accountId: 708, sessionName: 'wa_session_708', desiredState: 'running' });
  harness.emit(708, 'onAuthenticated');
  await flushAsync(3);
  const oldCallbacks = harness.callbacks.get('708');
  const oldTimer = harness.timers[0];

  await harness.manager.restart(708, 'refresh');
  const newCallbacks = harness.callbacks.get('708');
  newCallbacks.onAuthenticated();
  newCallbacks.onReady();
  await flushAsync(4);

  if (!oldTimer.cleared) {
    oldTimer.cleared = true;
    await oldTimer.callback();
  }

  oldCallbacks.onQr('RAW-QR-OLD');
  oldCallbacks.onAuthenticated();
  oldCallbacks.onReady();
  oldCallbacks.onDisconnected('stale');
  await flushAsync(4);

  const snapshot = harness.manager.getSnapshot(708);
  const errorCalls = harness.statusCalls.filter((entry) => entry.accountId === 708 && entry.status === 'error');
  const connectedCalls = harness.statusCalls.filter((entry) => entry.accountId === 708 && entry.status === 'connected');

  assert.equal(snapshot.isReady, true);
  assert.equal(snapshot.state, 'ready');
  assert.equal(errorCalls.length, 0);
  assert.equal(connectedCalls.length, 1);
  assert.equal(harness.logCalls.some((entry) => JSON.stringify(entry).includes('RAW-QR-OLD')), false);
});

test('callbacks from the current generation are ignored after stop begins', async () => {
  const harness = createHarness({ slowDestroyFor: 709, readinessTimeoutMs: 5000 });

  await harness.manager.start({ accountId: 709, sessionName: 'wa_session_709', desiredState: 'running' });
  harness.emit(709, 'onAuthenticated');
  await flushAsync(3);

  const stopPromise = harness.manager.stop(709);
  await harness.waitForDestroy(709);

  harness.emit(709, 'onQr', 'RAW-QR-709');
  harness.emit(709, 'onAuthenticated');
  harness.emit(709, 'onReady');
  await flushAsync(4);

  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 709), false);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 709 && entry.status === 'qr_required'), false);
  assert.equal(harness.statusCalls.filter((entry) => entry.accountId === 709 && entry.status === 'authenticated').length, 1);

  harness.resolveDestroy(709);
  await stopPromise;
});

test('stop disconnected and error cancel readiness timers', async () => {
  const harness = createHarness({ readinessTimeoutMs: 1500 });

  await harness.manager.start({ accountId: 710, sessionName: 'wa_session_710', desiredState: 'running' });
  harness.emit(710, 'onAuthenticated');
  await flushAsync(3);
  await harness.manager.stop(710);
  assert.equal(harness.manager.getSnapshot(710).waitingForReady, false);

  await harness.manager.start({ accountId: 711, sessionName: 'wa_session_711', desiredState: 'running' });
  harness.emit(711, 'onAuthenticated');
  harness.emit(711, 'onDisconnected', 'network');
  await flushAsync(3);
  assert.equal(harness.manager.getSnapshot(711).waitingForReady, false);

  await harness.manager.start({ accountId: 712, sessionName: 'wa_session_712', desiredState: 'running' });
  harness.emit(712, 'onAuthenticated');
  harness.emit(712, 'onError', new Error('boom'));
  await flushAsync(3);
  assert.equal(harness.manager.getSnapshot(712).waitingForReady, false);
});

test('two sessions maintain independent readiness timers after authentication', async () => {
  const harness = createHarness({ readinessTimeoutMs: 2000 });

  await harness.manager.start({ accountId: 713, sessionName: 'wa_session_713', desiredState: 'running' });
  await harness.manager.start({ accountId: 714, sessionName: 'wa_session_714', desiredState: 'running' });
  harness.emit(713, 'onAuthenticated');
  harness.emit(714, 'onAuthenticated');
  await flushAsync(3);

  const first = harness.manager.getSnapshot(713);
  const second = harness.manager.getSnapshot(714);

  assert.equal(first.waitingForReady, true);
  assert.equal(second.waitingForReady, true);
  assert.equal(harness.timers.length, 2);
  assert.equal(harness.timers[0] !== harness.timers[1], true);
});

test('storing qr failures do not prevent qr_required status reporting', async () => {
  const harness = createHarness({ statusFailures: ['store_qr'] });

  await harness.manager.start({ accountId: 715, sessionName: 'wa_session_715', desiredState: 'running' });
  harness.emit(715, 'onQr', 'RAW-QR-715');
  await flushAsync(4);

  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 715 && entry.status === 'store_qr'), true);
  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 715 && entry.status === 'qr_required'), true);
  assert.equal(harness.logCalls.some((entry) => entry.level === 'warn' && String(entry.args[0]).includes('Failed to store managed session QR.')), true);
});

test('status update failures do not prevent ready from starting the worker', async () => {
  const harness = createHarness({ statusFailures: ['connected'] });

  await harness.manager.start({ accountId: 716, sessionName: 'wa_session_716', desiredState: 'running' });
  harness.emit(716, 'onAuthenticated');
  harness.emit(716, 'onReady');
  await flushAsync(4);

  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 716 && entry.status === 'connected'), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 716), true);
  assert.equal(harness.logCalls.some((entry) => entry.level === 'warn' && String(entry.args[0]).includes('Failed to update managed session status.')), true);
});

test('session snapshots stay free of tokens qr raw payloads and client objects', async () => {
  const harness = createHarness();

  await harness.manager.start({ accountId: 717, sessionName: 'wa_session_717', desiredState: 'running' });
  const snapshot = harness.manager.getSnapshot(717);

  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'token'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'qr'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'client'), false);
  assert.equal(snapshot.hasStatusClient, true);
});