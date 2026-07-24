const test = require('node:test');
const assert = require('node:assert/strict');
const { config } = require('../src/config');
const indexModule = require('../src/index');

const createFakeSessionManager = (calls) => ({
  has(accountId) {
    return calls.active.has(String(accountId));
  },
  async start(descriptor) {
    calls.started.push(descriptor);
    calls.active.set(String(descriptor.accountId), { accountId: descriptor.accountId, sessionName: descriptor.sessionName });
  },
  async stop(accountId) {
    calls.stopped.push(String(accountId));
    calls.active.delete(String(accountId));
  },
  async remove(accountId) {
    calls.removed.push(String(accountId));
    calls.active.delete(String(accountId));
    return true;
  },
  async shutdownAll() {
    calls.shutdownAll += 1;
    calls.active.clear();
    return { total: 0, succeeded: 0, failed: 0, results: [] };
  },
  getAllSnapshots() {
    return Array.from(calls.active.values());
  },
});

test.afterEach(() => {
  config.multiSessionEnabled = false;
  config.whatsappEngineInternalToken = '';
});

test('multi-session worker factory builds central account-scoped message clients', async () => {
  config.multiSessionEnabled = true;
  config.whatsappEngineInternalToken = 'internal-token';
  const calls = {
    createdMessageClients: [],
    started: [],
    stopped: [],
    removed: [],
    active: new Map(),
    shutdownAll: 0,
  };

  const runtime = indexModule.createMultiSessionRuntime({
    sessionManager: createFakeSessionManager(calls),
    laravelClient: {
      async getEngineSessions() {
        return {
          success: true,
          data: [
            { id: 701, session_name: 'wa_session_701', session_desired_state: 'running' },
            { id: 702, session_name: 'wa_session_702', session_desired_state: 'running' },
          ],
        };
      },
    },
    createMessageWorker: (descriptor, helpers) => {
      const worker = indexModule.buildSessionMessageWorkerFactory({
        createMessageClient: ({ accountId }) => {
          calls.createdMessageClients.push({ accountId, sessionName: descriptor.sessionName, token: config.whatsappEngineInternalToken });
          return {
            async fetchPendingMessages() { return { success: true, data: [], meta: { limit: 2 } }; },
            async claimMessage() { return { success: true, data: {} }; },
            async fetchQueuedMessages() { return { success: true, data: [] }; },
            async markMessageSent() { return { success: true, data: {} }; },
            async markMessageFailed() { return { success: true, data: {} }; },
          };
        },
        setInterval: () => ({ descriptor }),
        clearInterval: () => {},
      })(descriptor, helpers);

      return worker;
    },
    setInterval: () => ({ timer: true }),
    clearInterval: () => {},
  });

  await runtime.start();

  assert.deepEqual(calls.started.map((entry) => entry.accountId), [701, 702]);

  const factory = indexModule.buildSessionMessageWorkerFactory({
    createMessageClient: ({ accountId }) => {
      calls.createdMessageClients.push({ accountId, token: config.whatsappEngineInternalToken });
      return {
        async fetchPendingMessages() { return { success: true, data: [], meta: { limit: 2 } }; },
        async claimMessage() { return { success: true, data: {} }; },
        async fetchQueuedMessages() { return { success: true, data: [] }; },
        async markMessageSent() { return { success: true, data: {} }; },
        async markMessageFailed() { return { success: true, data: {} }; },
      };
    },
    setInterval: () => ({ timer: true }),
    clearInterval: () => {},
  });

  const worker701 = factory({ accountId: 701, sessionName: 'wa_session_701' }, {
    getWhatsappClient: () => ({ async getNumberId() { return null; }, async sendMessage() {} }),
    isReady: () => true,
  });
  await worker701.start();

  const worker702 = factory({ accountId: 702, sessionName: 'wa_session_702' }, {
    getWhatsappClient: () => ({ async getNumberId() { return null; }, async sendMessage() {} }),
    isReady: () => true,
  });
  await worker702.start();

  assert.equal(calls.createdMessageClients.some((entry) => entry.accountId === 701 && entry.token === 'internal-token'), true);
  assert.equal(calls.createdMessageClients.some((entry) => entry.accountId === 702 && entry.token === 'internal-token'), true);
  assert.equal(calls.createdMessageClients.some((entry) => entry.token === 'legacy-token'), false);
});