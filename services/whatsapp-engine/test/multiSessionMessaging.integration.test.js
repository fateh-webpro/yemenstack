const test = require('node:test');
const assert = require('node:assert/strict');
const { SessionManager } = require('../src/sessionManager');
const { SessionMessageWorker } = require('../src/sessionMessageWorker');

const flushAsync = async (times = 1) => {
  for (let index = 0; index < times; index += 1) {
    await new Promise((resolve) => setImmediate(resolve));
  }
};

const waitFor = async (predicate, attempts = 50) => {
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    if (predicate()) {
      return true;
    }

    await flushAsync();
  }

  return predicate();
};

const createWorkerFactory = (calls, options = {}) => {
  return (descriptor, helpers) => new SessionMessageWorker({
    accountId: descriptor.accountId,
    sessionName: descriptor.sessionName,
    getWhatsappClient: helpers.getWhatsappClient,
    isReady: helpers.isReady,
    createMessageClient: ({ accountId, sessionName }) => {
      calls.push({ type: 'createMessageClient', accountId, sessionName });

      if (typeof options.createMessageClient === 'function') {
        return options.createMessageClient({ accountId, sessionName, descriptor });
      }

      return {
        async fetchPendingMessages() {
          calls.push({ type: 'fetchPendingMessages', accountId: descriptor.accountId, urlAccountId: accountId });
          return { success: true, data: [] };
        },
        async claimMessage(messageId) {
          calls.push({ type: 'claimMessage', accountId: descriptor.accountId, urlAccountId: accountId, messageId });
          return { success: true, data: { message_id: messageId, status: 'queued' } };
        },
        async fetchQueuedMessages() {
          const message = {
            id: descriptor.accountId * 10,
            recipient: descriptor.accountId === 701 ? '967700000701' : '967700000702',
            body: `body-${descriptor.accountId}`,
          };
          calls.push({ type: 'fetchQueuedMessages', accountId: descriptor.accountId, urlAccountId: accountId });
          return { success: true, data: [message] };
        },
        async markMessageSent(messageId, payload) {
          calls.push({ type: 'markMessageSent', accountId: descriptor.accountId, urlAccountId: accountId, messageId, payload });
          return { success: true, data: { id: messageId, status: 'sent' } };
        },
        async markMessageFailed(messageId, payload) {
          calls.push({ type: 'markMessageFailed', accountId: descriptor.accountId, urlAccountId: accountId, messageId, payload });
          return { success: true, data: { id: messageId, status: 'failed' } };
        },
      };
    },
    logger: {
      info: () => {},
      warn: () => {},
      error: () => {},
    },
    pollIntervalMs: 1000,
    fetchLimit: 2,
    enableRealWhatsappSend: true,
    setInterval: () => ({ accountId: descriptor.accountId }),
    clearInterval: () => {},
  });
};

test('two sessions stay isolated with different accountIds and clients', async () => {
  const calls = [];
  const callbacks = new Map();
  const clientsByAccount = {
    '701': {
      async initialize() {},
      async destroy() {},
      async getNumberId(recipient) {
        return { _serialized: `${recipient}@c.us` };
      },
      async sendMessage(chatId, body) {
        calls.push({ type: 'sendMessage', accountId: 701, chatId, body });
        return { id: { _serialized: 'wamid.701' }, to: chatId };
      },
    },
    '702': {
      async initialize() {},
      async destroy() {},
      async getNumberId(recipient) {
        return { _serialized: `${recipient}@c.us` };
      },
      async sendMessage(chatId, body) {
        calls.push({ type: 'sendMessage', accountId: 702, chatId, body });
        return { id: { _serialized: 'wamid.702' }, to: chatId };
      },
    },
  };

  const manager = new SessionManager({
    createClient: async (descriptor, sessionCallbacks) => {
      callbacks.set(String(descriptor.accountId), sessionCallbacks);
      return clientsByAccount[String(descriptor.accountId)];
    },
    createMessageWorker: createWorkerFactory(calls),
  });

  await manager.start({ accountId: 701, sessionName: 'wa_session_701', desiredState: 'running' });
  await manager.start({ accountId: 702, sessionName: 'wa_session_702', desiredState: 'running' });
  callbacks.get('701').onReady();
  callbacks.get('702').onReady();
  await waitFor(() => calls.filter((entry) => entry.type === 'sendMessage').length === 2);

  assert.equal(calls.some((entry) => entry.type === 'fetchQueuedMessages' && entry.accountId === 701 && entry.urlAccountId === 701), true);
  assert.equal(calls.some((entry) => entry.type === 'fetchQueuedMessages' && entry.accountId === 702 && entry.urlAccountId === 702), true);
  assert.equal(calls.some((entry) => entry.type === 'sendMessage' && entry.accountId === 701 && entry.body === 'body-701'), true);
  assert.equal(calls.some((entry) => entry.type === 'sendMessage' && entry.accountId === 702 && entry.body === 'body-702'), true);
  assert.equal(calls.some((entry) => entry.type === 'fetchQueuedMessages' && entry.accountId === 701 && entry.urlAccountId === 702), false);
  assert.equal(calls.some((entry) => entry.type === 'fetchQueuedMessages' && entry.accountId === 702 && entry.urlAccountId === 701), false);
});

test('missing central client keeps the session ready but blocks only the message worker', async () => {
  const callbacks = new Map();

  const manager = new SessionManager({
    createClient: async (descriptor, sessionCallbacks) => {
      callbacks.set(String(descriptor.accountId), sessionCallbacks);
      return {
        async initialize() {},
        async destroy() {},
      };
    },
    createMessageWorker: createWorkerFactory([], {
      createMessageClient() {
        const error = new Error('WHATSAPP_ENGINE_INTERNAL_TOKEN is not configured.');
        error.code = 'WHATSAPP_ENGINE_INTERNAL_TOKEN_MISSING';
        throw error;
      },
    }),
  });

  await manager.start({ accountId: 703, sessionName: 'wa_session_703', desiredState: 'running' });
  callbacks.get('703').onReady();
  await waitFor(() => manager.getSnapshot(703).messageWorker.lastError !== null);

  const snapshot = manager.getSnapshot(703);
  assert.equal(snapshot.isReady, true);
  assert.equal(snapshot.messageWorker.isRunning, false);
  assert.equal(snapshot.messageWorker.lastError.code, 'WHATSAPP_ENGINE_INTERNAL_TOKEN_MISSING');
});