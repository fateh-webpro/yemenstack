const test = require('node:test');
const assert = require('node:assert/strict');
const { config, parseMultiSessionAccountIds } = require('../src/config');
const indexModule = require('../src/index');

test.afterEach(() => {
  config.multiSessionEnabled = false;
  config.engineApiToken = '';
  config.whatsappEngineInternalToken = '';
  config.multiSessionAccountIdsRaw = '';
  config.whatsappSessionId = 'default';
  config.laravelBaseUrl = 'http://127.0.0.1:8000';
  config.pollIntervalMs = 5000;
});

test('parseMultiSessionAccountIds handles empty single multi and duplicate ids', () => {
  assert.deepEqual(parseMultiSessionAccountIds(''), []);
  assert.deepEqual(parseMultiSessionAccountIds('5'), [5]);
  assert.deepEqual(parseMultiSessionAccountIds('5,8'), [5, 8]);
  assert.deepEqual(parseMultiSessionAccountIds('5,8,5'), [5, 8]);
});

test('parseMultiSessionAccountIds rejects invalid zero and negative-like values', () => {
  assert.throws(() => parseMultiSessionAccountIds('abc'), /positive numeric account ids/);
  assert.throws(() => parseMultiSessionAccountIds('0'), /positive numeric account ids/);
  assert.throws(() => parseMultiSessionAccountIds('-5'), /positive numeric account ids/);
});

test('legacy validation requires laravel base url engine token and whatsapp session id only', () => {
  const runtimeConfig = {
    laravelBaseUrl: 'http://127.0.0.1:8000',
    engineApiToken: 'legacy-token',
    whatsappSessionId: 'legacy-session',
  };

  assert.equal(indexModule.validateLegacyRuntimeConfig(runtimeConfig), true);
  assert.throws(() => indexModule.validateLegacyRuntimeConfig({ ...runtimeConfig, engineApiToken: '' }), /ENGINE_API_TOKEN is required/);
  assert.throws(() => indexModule.validateLegacyRuntimeConfig({ ...runtimeConfig, whatsappSessionId: '' }), /WHATSAPP_SESSION_ID is required/);
});

test('multi-session validation requires laravel base url and internal token but not legacy token or session id', () => {
  const runtimeConfig = {
    laravelBaseUrl: 'http://127.0.0.1:8000',
    whatsappEngineInternalToken: 'internal-token',
    multiSessionAccountIdsRaw: '5,8',
    pollIntervalMs: 5000,
    engineApiToken: '',
    whatsappSessionId: '',
  };

  const result = indexModule.validateMultiSessionRuntimeConfig(runtimeConfig);
  assert.deepEqual(result.accountIdAllowlist, [5, 8]);
  assert.throws(() => indexModule.validateMultiSessionRuntimeConfig({ ...runtimeConfig, whatsappEngineInternalToken: '' }), /WHATSAPP_ENGINE_INTERNAL_TOKEN is required/);
});

test('createEngineRuntime throws early when multi-session configuration is incomplete', () => {
  config.multiSessionEnabled = true;
  config.whatsappEngineInternalToken = '';

  assert.throws(() => indexModule.createEngineRuntime(), /WHATSAPP_ENGINE_INTERNAL_TOKEN is required/);
});

test('createEngineRuntime passes allowlist into multi-session runtime', () => {
  config.multiSessionEnabled = true;
  config.whatsappEngineInternalToken = 'internal-token';
  config.multiSessionAccountIdsRaw = '5,8';

  const runtime = indexModule.createEngineRuntime({
    sessionManager: {
      has() { return false; },
      async start() {},
      async stop() {},
      async remove() { return true; },
      async shutdownAll() { return { total: 0, succeeded: 0, failed: 0, results: [] }; },
      getAllSnapshots() { return []; },
    },
    laravelClient: { async getEngineSessions() { return { success: true, data: [] }; } },
    setInterval: () => ({ timer: true }),
    clearInterval: () => {},
  });

  assert.equal(runtime.mode, 'multi-session');
  assert.deepEqual(runtime.accountIdAllowlist, ['5', '8']);
  assert.deepEqual(runtime.getSnapshot().accountIdAllowlist, ['5', '8']);
});

test('shutdownActiveRuntime is idempotent for a started legacy runtime', async () => {
  let shutdownCalls = 0;
  let startCalls = 0;
  const originalExit = process.exit;
  process.exit = () => {};

  try {
    config.multiSessionEnabled = false;
    config.engineApiToken = 'legacy-token';
    config.whatsappSessionId = 'legacy-session';

    await indexModule.startEngine({
      createLegacyRuntime: () => ({
        mode: 'legacy',
        async start() {
          startCalls += 1;
        },
        async shutdown() {
          shutdownCalls += 1;
        },
      }),
    });

    const first = indexModule.shutdownActiveRuntime('SIGTERM');
    const second = indexModule.shutdownActiveRuntime('SIGINT');
    await first;
    await second;
    assert.equal(startCalls, 1);
    assert.equal(shutdownCalls, 1);
  } finally {
    process.exit = originalExit;
  }
});