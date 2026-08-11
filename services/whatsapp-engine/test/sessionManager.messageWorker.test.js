const test = require('node:test');
const assert = require('node:assert/strict');
const { SessionManager } = require('../src/sessionManager');

const createHarness = () => {
  const workerCalls = [];
  const workers = new Map();
  const callbacks = new Map();
  let createdClientCount = 0;

  const manager = new SessionManager({
    createClient: async (descriptor, sessionCallbacks) => {
      createdClientCount += 1;
      callbacks.set(String(descriptor.accountId), sessionCallbacks);
      return {
        async initialize() {},
        async destroy() {},
      };
    },
    createMessageWorker: (descriptor, helpers) => {
      const workerState = {
        isRunning: false,
        timerCount: 0,
      };

      const worker = {
        async start() {
          workerCalls.push({ type: 'start_invoked', accountId: descriptor.accountId, generation: helpers.getGeneration() });

          if (workerState.isRunning) {
            return worker.getSnapshot();
          }

          workerState.isRunning = true;
          workerState.timerCount += 1;
          workerCalls.push({ type: 'started', accountId: descriptor.accountId, generation: helpers.getGeneration(), timerCount: workerState.timerCount });
          return worker.getSnapshot();
        },
        async stop(reason) {
          workerCalls.push({ type: 'stop', accountId: descriptor.accountId, reason, generation: helpers.getGeneration() });
          workerState.isRunning = false;
          return worker.getSnapshot();
        },
        getSnapshot() {
          return {
            accountId: descriptor.accountId,
            state: workerState.isRunning ? 'running' : 'idle',
            isRunning: workerState.isRunning,
            timerCount: workerState.timerCount,
          };
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
    getCreatedClientCount() {
      return createdClientCount;
    },
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

  assert.equal(harness.workerCalls.some((entry) => entry.type === 'started' && entry.accountId === 601), true);
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

  const startCalls = harness.workerCalls.filter((entry) => entry.type === 'started' && entry.accountId === 603);
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

test('ensureMessageWorkerStarted is safe after onReady and does not create a duplicate running worker or timer', async () => {
  const harness = createHarness();

  await harness.manager.start({ accountId: 606, sessionName: 'wa_session_606', desiredState: 'running' });

  await harness.manager.ensureMessageWorkerStarted(606);
  assert.equal(harness.workerCalls.length, 0);
  assert.equal(harness.getCreatedClientCount(), 1);

  harness.emit(606, 'onReady');
  await Promise.resolve();

  const runningWorker = harness.workers.get('606');
  const beforeSnapshot = runningWorker.getSnapshot();
  const beforeStartedCalls = harness.workerCalls.filter((entry) => entry.type === 'started' && entry.accountId === 606).length;

  await harness.manager.ensureMessageWorkerStarted(606);

  const afterSnapshot = runningWorker.getSnapshot();
  const afterStartedCalls = harness.workerCalls.filter((entry) => entry.type === 'started' && entry.accountId === 606).length;

  assert.equal(beforeSnapshot.isRunning, true);
  assert.equal(beforeSnapshot.timerCount, 1);
  assert.equal(afterSnapshot.isRunning, true);
  assert.equal(afterSnapshot.timerCount, 1);
  assert.equal(beforeStartedCalls, 1);
  assert.equal(afterStartedCalls, 1);
  assert.equal(harness.getCreatedClientCount(), 1);

  await harness.manager.stop(606);
  await harness.manager.ensureMessageWorkerStarted(606);

  const finalSnapshot = runningWorker.getSnapshot();
  const finalStartedCalls = harness.workerCalls.filter((entry) => entry.type === 'started' && entry.accountId === 606).length;
  assert.equal(finalSnapshot.timerCount, 1);
  assert.equal(finalStartedCalls, 1);
  assert.equal(harness.getCreatedClientCount(), 1);
});