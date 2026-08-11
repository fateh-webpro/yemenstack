const test = require('node:test');
const assert = require('node:assert/strict');
const { config } = require('../src/config');
const indexModule = require('../src/index');

test.afterEach(() => {
  config.multiSessionEnabled = false;
});

test('requiring index does not auto-start any runtime', () => {
  assert.equal(typeof indexModule.startEngine, 'function');
  assert.equal(typeof indexModule.createEngineRuntime, 'function');
});

test('feature flag false selects the legacy runtime only', () => {
  config.multiSessionEnabled = false;
  let legacyCreated = 0;
  let multiCreated = 0;

  const runtime = indexModule.createEngineRuntime({
    createLegacyRuntime: () => {
      legacyCreated += 1;
      return { mode: 'legacy' };
    },
    createMultiSessionRuntime: () => {
      multiCreated += 1;
      return { mode: 'multi' };
    },
  });

  assert.equal(runtime.mode, 'legacy');
  assert.equal(legacyCreated, 1);
  assert.equal(multiCreated, 0);
});

test('feature flag true selects the multi-session runtime only', () => {
  config.multiSessionEnabled = true;
  let legacyCreated = 0;
  let multiCreated = 0;

  const runtime = indexModule.createEngineRuntime({
    createLegacyRuntime: () => {
      legacyCreated += 1;
      return { mode: 'legacy' };
    },
    createMultiSessionRuntime: () => {
      multiCreated += 1;
      return { mode: 'multi' };
    },
  });

  assert.equal(runtime.mode, 'multi');
  assert.equal(legacyCreated, 0);
  assert.equal(multiCreated, 1);
});
test('multi-session worker factory reads the live automatic sending mode from the provided context', () => {
  const context = {
    automaticSendingEnabled: false,
  };

  const factory = indexModule.buildSessionMessageWorkerFactory({
    createMessageClient: () => ({
      async fetchPendingMessages() { return { success: true, data: [], meta: { limit: 1 } }; },
      async claimMessage() { return { success: true, data: {} }; },
      async fetchQueuedMessages() { return { success: true, data: [] }; },
      async markMessageSent() { return { success: true, data: {} }; },
      async markMessageFailed() { return { success: true, data: {} }; },
    }),
    setInterval: () => ({ timer: true }),
    clearInterval: () => {},
  });

  const worker = factory({ accountId: 801, sessionName: 'wa_session_801' }, {
    getContext: () => context,
    getWhatsappClient: () => null,
    isReady: () => false,
  });

  assert.equal(worker.getSnapshot().sendingMode, 'manual');

  context.automaticSendingEnabled = true;
  assert.equal(worker.getSnapshot().sendingMode, 'automatic');

  context.automaticSendingEnabled = false;
  assert.equal(worker.getSnapshot().sendingMode, 'manual');
});
