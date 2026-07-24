const test = require('node:test');
const assert = require('node:assert/strict');
const { SessionManager } = require('../src/sessionManager');

const createHarness = () => {
  const workerCalls = [];
  const workers = new Map();
  const callbacks = new Map();

  const manager = new SessionManager({
    createClient: async (descriptor, sessionCallbacks) => {
      callbacks.set(String(descriptor.accountId), sessionCallbacks);
      return {
        async initialize() {},
        async destroy() {},
      };
    },
    createMessageWorker: (descriptor, helpers) => {
      const worker = {
        async start() {
          workerCalls.push({ type: 'start', accountId: descriptor.accountId, generation: helpers.getGeneration() });
        },
        async stop(reason) {
          workerCalls.push({ type: 'stop', accountId: descriptor.accountId, reason, generation: helpers.getGeneration() });
        },
        getSnapshot() {
          return { accountId: descriptor.accountId, state: 'idle' };
        },
      };

      workers.set(String(descriptor.accountId), worker);
      return worker;
    },
  });

  return {
    manager,
    workerCalls,
    callbacks,
    workers,
    emit(accountId, eventName, payload) {
      callbacks.get(String(accountId))[eventName](payload);
    },
  };
};

test('ready starts the worker and disconnected stops only that worker', async () => {
  const harness = createHarness();

  await harness.manager.start({ accountId: 601, sessionName: 'wa_session_601', desiredState: 'running' });
  await harness.manager.start({ accountId: 602, sessionName: 'wa_session_602', desiredState: 'running' });

  harness.emit(601, 'onReady');
  await Promise.resolve();
  harness.emit(601, 'onDisconnected', 'network');
  await Promise.resolve();

  assert.equal(harness.workerCalls.some((entry) => entry.type === 'start' && entry.accountId === 601), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'stop' && entry.accountId === 601), true);
  assert.equal(harness.workerCalls.some((entry) => entry.type === 'stop' && entry.accountId === 602), false);
});

test('restart stops the old worker and old callbacks do not restart it after a new generation', async () => {
  const harness = createHarness();

  await harness.manager.start({ accountId: 603, sessionName: 'wa_session_603', desiredState: 'running' });
  const oldCallbacks = harness.callbacks.get('603');
  harness.emit(603, 'onReady');
  await Promise.resolve();

  await harness.manager.restart(603, 'recoverable');
  const newCallbacks = harness.callbacks.get('603');
  newCallbacks.onReady();
  await Promise.resolve();
  oldCallbacks.onReady();
  await Promise.resolve();

  const startCalls = harness.workerCalls.filter((entry) => entry.type === 'start' && entry.accountId === 603);
  assert.equal(startCalls.length, 2);
  assert.equal(startCalls[0].generation < startCalls[1].generation, true);
});

test('shutdownAll stops all workers', async () => {
  const harness = createHarness();

  await harness.manager.start({ accountId: 604, sessionName: 'wa_session_604', desiredState: 'running' });
  await harness.manager.start({ accountId: 605, sessionName: 'wa_session_605', desiredState: 'running' });
  harness.emit(604, 'onReady');
  harness.emit(605, 'onReady');
  await Promise.resolve();

  await harness.manager.shutdownAll();

  assert.equal(harness.workerCalls.filter((entry) => entry.type === 'stop').length >= 2, true);
});