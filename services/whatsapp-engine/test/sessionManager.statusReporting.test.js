const test = require('node:test');
const assert = require('node:assert/strict');
const { SessionManager } = require('../src/sessionManager');

const createHarness = (options = {}) => {
  const callbacks = new Map();
  const workerCalls = [];
  const statusCalls = [];
  const logCalls = [];
  const statusFailures = new Set(options.statusFailures || []);

  const manager = new SessionManager({
    createClient: async (descriptor, sessionCallbacks) => {
      callbacks.set(String(descriptor.accountId), sessionCallbacks);
      return {
        async initialize() {},
        async destroy() {},
      };
    },
    createStatusClient: (descriptor) => ({
      async updateSessionStatus(status, extra = {}) {
        statusCalls.push({ accountId: descriptor.accountId, status, extra });

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
    emit(accountId, eventName, payload) {
      callbacks.get(String(accountId))[eventName](payload);
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
  await Promise.resolve();
  await Promise.resolve();

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

test('status update failures do not prevent ready from starting the worker', async () => {
  const harness = createHarness({ statusFailures: ['connected'] });

  await harness.manager.start({ accountId: 703, sessionName: 'wa_session_703', desiredState: 'running' });
  harness.emit(703, 'onReady');
  await Promise.resolve();
  await Promise.resolve();

  assert.equal(harness.statusCalls.some((entry) => entry.accountId === 703 && entry.status === 'connected'), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 703), true);
  assert.equal(harness.logCalls.some((entry) => entry.level === 'warn' && String(entry.args[0]).includes('Failed to update managed session status.')), true);
});

test('old generation callbacks do not report status or restart the worker', async () => {
  const harness = createHarness();

  await harness.manager.start({ accountId: 704, sessionName: 'wa_session_704', desiredState: 'running' });
  const oldCallbacks = harness.callbacks.get('704');
  harness.emit(704, 'onReady');
  await Promise.resolve();

  await harness.manager.restart(704, 'refresh');
  const newCallbacks = harness.callbacks.get('704');
  newCallbacks.onReady();
  await Promise.resolve();
  oldCallbacks.onReady();
  oldCallbacks.onDisconnected('stale');
  await Promise.resolve();
  await Promise.resolve();

  const connectedCalls = harness.statusCalls.filter((entry) => entry.accountId === 704 && entry.status === 'connected');
  const disconnectedCalls = harness.statusCalls.filter((entry) => entry.accountId === 704 && entry.status === 'disconnected');

  assert.equal(connectedCalls.length, 2);
  assert.equal(disconnectedCalls.length, 0);

  const startCalls = harness.workerCalls.filter((entry) => entry.type === 'start' && entry.accountId === 704);
  assert.equal(startCalls.length, 2);
});

test('session snapshots stay free of tokens qr raw payloads and client objects', async () => {
  const harness = createHarness();

  await harness.manager.start({ accountId: 705, sessionName: 'wa_session_705', desiredState: 'running' });
  const snapshot = harness.manager.getSnapshot(705);

  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'token'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'qr'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'client'), false);
  assert.equal(snapshot.hasStatusClient, true);
});