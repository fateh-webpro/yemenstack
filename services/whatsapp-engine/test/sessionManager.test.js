const test = require('node:test');
const assert = require('node:assert/strict');
const { SessionManager, isSafeSessionName } = require('../src/sessionManager');

const createHarness = (options = {}) => {
  const counters = {
    createClient: 0,
    initialize: 0,
    destroy: 0,
    startPolling: 0,
    stopPolling: 0,
    externalDestroy: 0,
  };

  const callbacksByAccount = new Map();
  const clientsByAccount = new Map();
  const loggerCalls = [];

  const logger = {
    info: (...args) => loggerCalls.push({ level: 'info', args }),
    warn: (...args) => loggerCalls.push({ level: 'warn', args }),
    error: (...args) => loggerCalls.push({ level: 'error', args }),
  };

  const manager = new SessionManager({
    logger,
    createClient: async (descriptor, callbacks) => {
      counters.createClient += 1;
      callbacksByAccount.set(String(descriptor.accountId), callbacks);

      const client = {
        descriptor,
        initialized: false,
        destroyed: false,
        async initialize() {
          counters.initialize += 1;
          client.initialized = true;

          if (typeof options.onInitialize === 'function') {
            await options.onInitialize({ descriptor, callbacks, client });
          }
        },
        async destroy() {
          counters.destroy += 1;
          client.destroyed = true;

          if (options.failDestroyFor === descriptor.accountId) {
            throw new Error(`Destroy failed for ${descriptor.accountId}`);
          }
        },
      };

      clientsByAccount.set(String(descriptor.accountId), client);
      return client;
    },
    destroyClient: async (client, context) => {
      counters.externalDestroy += 1;

      if (options.failExternalDestroyFor === context.accountId) {
        throw new Error(`External destroy failed for ${context.accountId}`);
      }

      return client.destroy();
    },
    startPolling: (context) => {
      counters.startPolling += 1;
      return { accountId: context.accountId, timer: true };
    },
    stopPolling: () => {
      counters.stopPolling += 1;
      return null;
    },
  });

  return {
    manager,
    counters,
    loggerCalls,
    callbacksByAccount,
    clientsByAccount,
    emit(accountId, eventName, payload) {
      const callbacks = callbacksByAccount.get(String(accountId));
      assert.ok(callbacks, `Callbacks for account ${accountId} were not registered.`);
      assert.equal(typeof callbacks[eventName], 'function', `Callback ${eventName} is missing.`);
      callbacks[eventName](payload);
    },
  };
};

test('creates an empty manager', () => {
  const { manager } = createHarness();

  assert.equal(manager.list().length, 0);
  assert.deepEqual(manager.getAllSnapshots(), []);
});

test('start creates one session context and initializes the client once', async () => {
  const { manager, counters } = createHarness();

  const snapshot = await manager.start({
    accountId: 101,
    sessionName: 'wa_session_101',
    desiredState: 'running',
  });

  assert.equal(counters.createClient, 1);
  assert.equal(counters.initialize, 1);
  assert.equal(manager.list().length, 1);
  assert.equal(snapshot.accountId, 101);
  assert.equal(snapshot.sessionName, 'wa_session_101');
  assert.equal(snapshot.state, 'running');
  assert.equal(snapshot.hasClient, true);
});

test('start is idempotent while a session already exists for the same account', async () => {
  const { manager, counters } = createHarness();

  await manager.start({
    accountId: 102,
    sessionName: 'wa_session_102',
    desiredState: 'running',
  });

  const secondStart = await manager.start({
    accountId: 102,
    sessionName: 'wa_session_102',
    desiredState: 'running',
  });

  assert.equal(counters.createClient, 1);
  assert.equal(counters.initialize, 1);
  assert.equal(secondStart.accountId, 102);
  assert.equal(manager.list().length, 1);
});

test('prevents duplicate account ids with a different session name', async () => {
  const { manager } = createHarness();

  await manager.start({
    accountId: 103,
    sessionName: 'wa_session_103',
    desiredState: 'running',
  });

  await assert.rejects(
    () => manager.start({
      accountId: 103,
      sessionName: 'wa_other_103',
      desiredState: 'running',
    }),
    /different session name/
  );
});

test('prevents reusing the same session name for two accounts', async () => {
  const { manager } = createHarness();

  await manager.start({
    accountId: 104,
    sessionName: 'wa_shared_session',
    desiredState: 'running',
  });

  await assert.rejects(
    () => manager.start({
      accountId: 105,
      sessionName: 'wa_shared_session',
      desiredState: 'running',
    }),
    /already in use/
  );
});

test('rejects unsafe session names', async () => {
  const { manager } = createHarness();

  assert.equal(isSafeSessionName('wa_safe_1'), true);
  assert.equal(isSafeSessionName('wa_bad/1'), false);
  assert.equal(isSafeSessionName('wa_bad\\1'), false);
  assert.equal(isSafeSessionName('wa_bad..1'), false);
  assert.equal(isSafeSessionName('unsafe'), false);

  await assert.rejects(
    () => manager.start({
      accountId: 106,
      sessionName: 'wa_bad/1',
      desiredState: 'running',
    }),
    /sessionName is invalid/
  );
});

test('different sessions run independently', async () => {
  const harness = createHarness();
  const { manager } = harness;

  await manager.start({ accountId: 107, sessionName: 'wa_session_107', desiredState: 'running' });
  await manager.start({ accountId: 108, sessionName: 'wa_session_108', desiredState: 'running' });

  harness.emit(107, 'onReady');
  harness.emit(108, 'onDisconnected', 'network');

  assert.equal(manager.getSnapshot(107).isReady, true);
  assert.equal(manager.getSnapshot(107).state, 'running');
  assert.equal(manager.getSnapshot(108).isReady, false);
  assert.equal(manager.getSnapshot(108).state, 'stopped');
});

test('session errors are isolated and do not stop other sessions', async () => {
  const harness = createHarness();
  const { manager } = harness;

  await manager.start({ accountId: 109, sessionName: 'wa_session_109', desiredState: 'running' });
  await manager.start({ accountId: 110, sessionName: 'wa_session_110', desiredState: 'running' });

  harness.emit(109, 'onError', new Error('Session 109 failed'));

  assert.equal(manager.getSnapshot(109).state, 'error');
  assert.equal(manager.getSnapshot(110).state, 'running');
});

test('ready event starts polling for only the matching session', async () => {
  const harness = createHarness();
  const { manager, counters } = harness;

  await manager.start({ accountId: 111, sessionName: 'wa_session_111', desiredState: 'running' });
  await manager.start({ accountId: 112, sessionName: 'wa_session_112', desiredState: 'running' });

  harness.emit(111, 'onReady');

  assert.equal(counters.startPolling, 1);
  assert.equal(manager.get(111).pollTimer !== null, true);
  assert.equal(manager.get(112).pollTimer, null);
});

test('stop destroys the client and stops polling', async () => {
  const harness = createHarness();
  const { manager, counters } = harness;

  await manager.start({ accountId: 113, sessionName: 'wa_session_113', desiredState: 'running' });
  harness.emit(113, 'onReady');

  const snapshot = await manager.stop(113);

  assert.equal(counters.externalDestroy, 1);
  assert.equal(counters.destroy, 1);
  assert.equal(counters.stopPolling >= 1, true);
  assert.equal(snapshot.state, 'stopped');
  assert.equal(snapshot.hasClient, false);
});

test('stop is idempotent', async () => {
  const harness = createHarness();
  const { manager, counters } = harness;

  await manager.start({ accountId: 114, sessionName: 'wa_session_114', desiredState: 'running' });
  await manager.stop(114);
  await manager.stop(114);

  assert.equal(counters.externalDestroy, 1);
  assert.equal(manager.getSnapshot(114).state, 'stopped');
});

test('restart increases generation and ignores old callbacks', async () => {
  const harness = createHarness();
  const { manager } = harness;

  await manager.start({ accountId: 115, sessionName: 'wa_session_115', desiredState: 'running' });
  const firstGeneration = manager.getSnapshot(115).generation;
  const oldCallbacks = harness.callbacksByAccount.get('115');

  await manager.restart(115, 'recoverable_error');
  const secondGeneration = manager.getSnapshot(115).generation;

  assert.equal(secondGeneration > firstGeneration, true);

  oldCallbacks.onError(new Error('Old generation error'));

  assert.equal(manager.getSnapshot(115).state, 'running');
  assert.equal(manager.getSnapshot(115).lastError, null);
});

test('restart is idempotent while already restarting', async () => {
  const harness = createHarness({
    onInitialize: async ({ descriptor, callbacks }) => {
      if (descriptor.accountId === 116) {
        callbacks.onReady();
      }
    },
  });
  const { manager, counters } = harness;

  await manager.start({ accountId: 116, sessionName: 'wa_session_116', desiredState: 'running' });

  const firstRestartPromise = manager.restart(116, 'first');
  const secondRestartPromise = manager.restart(116, 'second');

  assert.equal(manager.get(116).isRestarting, true);

  const [firstResult, secondResult] = await Promise.all([firstRestartPromise, secondRestartPromise]);

  assert.equal(counters.createClient, 2);
  assert.equal(firstResult.accountId, 116);
  assert.equal(secondResult.accountId, 116);
  assert.equal(manager.getSnapshot(116).generation, 2);
  assert.equal(manager.get(116).isRestarting, false);
});

test('destroy failure is logged and does not corrupt the session map', async () => {
  const harness = createHarness({ failExternalDestroyFor: 117 });
  const { manager, loggerCalls } = harness;

  await manager.start({ accountId: 117, sessionName: 'wa_session_117', desiredState: 'running' });
  await manager.stop(117);

  assert.equal(manager.has(117), true);
  assert.equal(manager.getSnapshot(117).state, 'stopped');
  assert.equal(loggerCalls.some((entry) => entry.level === 'warn'), true);
});

test('remove deletes only one stopped session', async () => {
  const harness = createHarness();
  const { manager } = harness;

  await manager.start({ accountId: 118, sessionName: 'wa_session_118', desiredState: 'running' });
  await manager.start({ accountId: 119, sessionName: 'wa_session_119', desiredState: 'running' });

  await manager.stop(118);
  const removed = await manager.remove(118);

  assert.equal(removed, true);
  assert.equal(manager.has(118), false);
  assert.equal(manager.has(119), true);
});

test('shutdownAll stops every session and continues when one destroy fails', async () => {
  const harness = createHarness({ failExternalDestroyFor: 120 });
  const { manager } = harness;

  await manager.start({ accountId: 120, sessionName: 'wa_session_120', desiredState: 'running' });
  await manager.start({ accountId: 121, sessionName: 'wa_session_121', desiredState: 'running' });

  const result = await manager.shutdownAll();

  assert.equal(result.total, 2);
  assert.equal(result.failed, 0);
  assert.equal(result.succeeded, 2);
  assert.equal(manager.getSnapshot(120).state, 'stopped');
  assert.equal(manager.getSnapshot(121).state, 'stopped');
});

test('snapshots never expose the client object', async () => {
  const { manager } = createHarness();

  await manager.start({ accountId: 122, sessionName: 'wa_session_122', desiredState: 'running' });

  const snapshot = manager.getSnapshot(122);

  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'client'), false);
  assert.equal(snapshot.hasClient, true);
});

test('the manager never imports whatsapp-web.js and does not touch external session paths', () => {
  const managerModulePath = require.resolve('../src/sessionManager');
  const managerSource = require('node:fs').readFileSync(managerModulePath, 'utf8');

  assert.equal(managerSource.includes('whatsapp-web.js'), false);
  assert.equal(managerSource.includes('.wwebjs_auth'), false);
  assert.equal(managerSource.includes('.wwebjs_cache'), false);
});