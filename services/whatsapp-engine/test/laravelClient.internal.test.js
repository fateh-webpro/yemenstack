const test = require('node:test');
const assert = require('node:assert/strict');
const { config } = require('../src/config');
const {
  createEngineSessionMessageClient,
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

test('engine session message client uses account-scoped central routes with internal token only', async () => {
  const calls = [];
  config.engineApiToken = 'legacy-token';
  global.fetch = async (url, options) => {
    calls.push({ url: String(url), options });

    return {
      ok: true,
      async json() {
        return { success: true, data: [], meta: { limit: 5 } };
      },
    };
  };

  const client = createEngineSessionMessageClient({
    internalToken: 'central-internal-token',
    accountId: 701,
  });

  await client.fetchPendingMessages(5);
  await client.claimMessage(11);
  await client.fetchQueuedMessages(3);
  await client.markMessageSent(11, { external_message_id: 'wamid.701' });
  await client.markMessageFailed(11, { error_message: 'failed' });
  await client.updateSessionStatus('connected', { last_seen_at: '2026-07-24T09:00:00.000Z' });

  assert.equal(calls.length, 6);
  assert.match(calls[0].url, /\/api\/v1\/whatsapp\/engine\/sessions\/701\/messages\/pending\?limit=5$/);
  assert.match(calls[1].url, /\/api\/v1\/whatsapp\/engine\/sessions\/701\/messages\/11\/claim$/);
  assert.match(calls[2].url, /\/api\/v1\/whatsapp\/engine\/sessions\/701\/messages\/queued\?limit=3$/);
  assert.match(calls[3].url, /\/api\/v1\/whatsapp\/engine\/sessions\/701\/messages\/11\/mark-sent$/);
  assert.match(calls[4].url, /\/api\/v1\/whatsapp\/engine\/sessions\/701\/messages\/11\/mark-failed$/);
  assert.match(calls[5].url, /\/api\/v1\/whatsapp\/engine\/sessions\/701\/status$/);
  assert.equal(calls.every((entry) => entry.options.headers.Authorization === 'Bearer central-internal-token'), true);
  assert.equal(calls.every((entry) => entry.options.headers.Authorization !== 'Bearer legacy-token'), true);
  assert.equal(JSON.parse(calls[5].options.body).status, 'connected');
});

test('engine session message client rejects missing internal token and invalid accountId', async () => {
  const missingTokenClient = createEngineSessionMessageClient({
    internalToken: '',
    accountId: 701,
  });

  await assert.rejects(
    () => missingTokenClient.fetchPendingMessages(),
    /WHATSAPP_ENGINE_INTERNAL_TOKEN is not configured/
  );

  await assert.rejects(
    () => missingTokenClient.updateSessionStatus('connected'),
    /WHATSAPP_ENGINE_INTERNAL_TOKEN is not configured/
  );

  assert.throws(
    () => createEngineSessionMessageClient({
      internalToken: 'central-internal-token',
      accountId: 'invalid',
    }),
    /A valid accountId is required/
  );
});

test('internal sessions client rejects when the central token is missing', async () => {
  config.whatsappEngineInternalToken = '';

  await assert.rejects(
    () => getEngineSessions(),
    /WHATSAPP_ENGINE_INTERNAL_TOKEN is not configured/
  );
});

test('internal clients errors do not expose the token value', async () => {
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

  const client = createEngineSessionMessageClient({
    internalToken: 'secret-token',
    accountId: 702,
  });

  await assert.rejects(
    () => client.fetchQueuedMessages(),
    (error) => {
      assert.equal(error.message.includes('secret-token'), false);
      return true;
    }
  );

  await assert.rejects(
    () => client.updateSessionStatus('error', { error_message: 'boom' }),
    (error) => {
      assert.equal(error.message.includes('secret-token'), false);
      return true;
    }
  );
});