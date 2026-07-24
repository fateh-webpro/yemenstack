const test = require('node:test');
const assert = require('node:assert/strict');
const { config } = require('../src/config');
const {
  getEngineSessions,
  getEngineSession,
  requestEngineSessionStart,
  requestEngineSessionStop,
} = require('../src/laravelClient');

test.afterEach(() => {
  delete global.fetch;
  config.whatsappEngineInternalToken = '';
  config.engineApiToken = '';
});

test('internal sessions client uses the central internal token', async () => {
  const calls = [];
  config.whatsappEngineInternalToken = 'internal-token';
  config.engineApiToken = 'legacy-token';
  global.fetch = async (url, options) => {
    calls.push({ url: String(url), options });

    return {
      ok: true,
      async json() {
        return { success: true, data: [] };
      },
    };
  };

  await getEngineSessions({ desired_state: 'running' });
  await getEngineSession(42);
  await requestEngineSessionStart(42);
  await requestEngineSessionStop(42);

  assert.equal(calls.length, 4);
  assert.match(calls[0].url, /desired_state=running/);
  assert.equal(calls[0].options.headers.Authorization, 'Bearer internal-token');
  assert.equal(calls[1].options.headers.Authorization, 'Bearer internal-token');
  assert.equal(calls[2].options.headers.Authorization, 'Bearer internal-token');
  assert.equal(calls[3].options.headers.Authorization, 'Bearer internal-token');
  assert.equal(calls[2].options.method, 'POST');
  assert.equal(calls[3].options.method, 'POST');
});

test('internal sessions client rejects when the central token is missing', async () => {
  config.whatsappEngineInternalToken = '';

  await assert.rejects(
    () => getEngineSessions(),
    /WHATSAPP_ENGINE_INTERNAL_TOKEN is not configured/
  );
});

test('internal sessions client errors do not expose the token value', async () => {
  config.whatsappEngineInternalToken = 'secret-token';
  global.fetch = async () => ({
    ok: false,
    status: 500,
    async json() {
      return { message: 'Server error.' };
    },
  });

  await assert.rejects(
    () => getEngineSessions(),
    (error) => {
      assert.equal(error.message.includes('secret-token'), false);
      return true;
    }
  );
});