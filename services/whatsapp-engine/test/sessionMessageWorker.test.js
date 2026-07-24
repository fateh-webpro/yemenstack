const test = require('node:test');
const assert = require('node:assert/strict');
const { SessionMessageWorker } = require('../src/sessionMessageWorker');

const createHarness = (options = {}) => {
  const calls = {
    createMessageClient: [],
    resolveApiToken: 0,
    createLaravelClient: [],
    fetchPendingMessages: [],
    claimMessage: [],
    fetchQueuedMessages: [],
    markMessageSent: [],
    markMessageFailed: [],
    getNumberId: [],
    sendMessage: [],
    intervals: [],
    clearIntervals: [],
    logger: [],
  };

  let ready = options.ready ?? true;
  let client = options.whatsappClient || {
    async getNumberId(recipient) {
      calls.getNumberId.push(recipient);
      return { _serialized: `${recipient}@c.us` };
    },
    async sendMessage(chatId, body) {
      calls.sendMessage.push({ chatId, body });
      return {
        id: { _serialized: `wamid.${body}` },
        to: chatId,
      };
    },
  };

  const fakeLaravelClient = options.laravelMessageClient || {
    async fetchPendingMessages(limit) {
      calls.fetchPendingMessages.push(limit);
      return {
        success: true,
        data: options.pendingMessages || [],
        meta: { limit },
      };
    },
    async claimMessage(messageId) {
      calls.claimMessage.push(messageId);
      return {
        success: true,
        data: {
          message_id: messageId,
          status: 'queued',
          attempt_id: messageId * 10,
          attempt_number: 1,
        },
      };
    },
    async fetchQueuedMessages(limit) {
      calls.fetchQueuedMessages.push(limit);
      return {
        success: true,
        data: options.queuedMessages || [],
      };
    },
    async markMessageSent(messageId, payload) {
      calls.markMessageSent.push({ messageId, payload });
      return { success: true, data: { id: messageId, status: 'sent' } };
    },
    async markMessageFailed(messageId, payload) {
      calls.markMessageFailed.push({ messageId, payload });
      return { success: true, data: { id: messageId, status: 'failed' } };
    },
  };

  const worker = new SessionMessageWorker({
    accountId: options.accountId ?? 501,
    sessionName: options.sessionName ?? 'wa_worker_501',
    isReady: () => ready,
    getWhatsappClient: () => client,
    createMessageClient: Object.prototype.hasOwnProperty.call(options, 'createMessageClient')
      ? options.createMessageClient
      : ({ accountId, sessionName }) => {
        calls.createMessageClient.push({ accountId, sessionName });
        return fakeLaravelClient;
      },
    resolveApiToken: Object.prototype.hasOwnProperty.call(options, 'resolveApiToken')
      ? options.resolveApiToken
      : (async () => {
        calls.resolveApiToken += 1;
        return options.apiToken ?? 'session-token-501';
      }),
    createLaravelClient: ({ apiToken }) => {
      calls.createLaravelClient.push(apiToken);
      return fakeLaravelClient;
    },
    logger: {
      info: (...args) => calls.logger.push({ level: 'info', args }),
      warn: (...args) => calls.logger.push({ level: 'warn', args }),
      error: (...args) => calls.logger.push({ level: 'error', args }),
    },
    pollIntervalMs: 1500,
    fetchLimit: 3,
    enableRealWhatsappSend: options.enableRealWhatsappSend ?? true,
    whatsappTestRecipient: options.whatsappTestRecipient ?? '',
    setInterval: (callback, ms) => {
      const timer = { callback, ms };
      calls.intervals.push(timer);
      return timer;
    },
    clearInterval: (timer) => {
      calls.clearIntervals.push(timer);
    },
  });

  return {
    worker,
    calls,
    setReady(value) {
      ready = value;
    },
    setClient(nextClient) {
      client = nextClient;
    },
  };
};

test('worker start is idempotent and does not run before ready', async () => {
  const harness = createHarness({ ready: false });

  await harness.worker.start();
  await harness.worker.start();

  assert.equal(harness.calls.createMessageClient.length, 0);
  assert.equal(harness.calls.resolveApiToken, 0);
  assert.equal(harness.worker.getSnapshot().isRunning, false);
  assert.equal(harness.calls.intervals.length, 0);
});

test('worker runCycle uses the central session message client for its own account', async () => {
  const harness = createHarness({
    accountId: 701,
    sessionName: 'wa_worker_701',
    pendingMessages: [{ id: 1, recipient: '967700000001', body: 'pending', status: 'pending' }],
    queuedMessages: [{ id: 2, recipient: '967700000002', body: 'hello queued' }],
  });

  await harness.worker.start();

  assert.deepEqual(harness.calls.createMessageClient, [{ accountId: 701, sessionName: 'wa_worker_701' }]);
  assert.deepEqual(harness.calls.fetchPendingMessages, [3]);
  assert.deepEqual(harness.calls.claimMessage, [1]);
  assert.deepEqual(harness.calls.fetchQueuedMessages, [3]);
  assert.equal(harness.calls.sendMessage.length, 1);
  assert.equal(harness.calls.markMessageSent.length, 1);
  assert.equal(harness.calls.resolveApiToken, 0);
  assert.equal(harness.calls.createLaravelClient.length, 0);
  assert.equal(harness.worker.getSnapshot().sentCount, 1);
});

test('runCycle is not re-entrant and returns the same promise while active', async () => {
  let release;
  const harness = createHarness();

  harness.worker.laravelMessageClient = {
    async fetchPendingMessages() {
      return new Promise((resolve) => {
        release = () => resolve({ success: true, data: [], meta: { limit: 3 } });
      });
    },
    async claimMessage() {
      throw new Error('unexpected');
    },
    async fetchQueuedMessages() {
      return { success: true, data: [] };
    },
    async markMessageSent() {},
    async markMessageFailed() {},
  };

  harness.worker.isRunning = true;

  const first = harness.worker.runCycle();
  const second = harness.worker.runCycle();
  assert.equal(first, second);
  await Promise.resolve();
  release();
  await first;
});

test('message send failure marks the message as failed and updates counters', async () => {
  const harness = createHarness({
    queuedMessages: [{ id: 8, recipient: '967700000008', body: 'fail me' }],
    whatsappClient: {
      async getNumberId(recipient) {
        harness.calls.getNumberId.push(recipient);
        return { _serialized: `${recipient}@c.us` };
      },
      async sendMessage() {
        throw new Error('send failed');
      },
    },
  });

  await harness.worker.start();

  assert.equal(harness.calls.markMessageFailed.length, 1);
  assert.equal(harness.worker.getSnapshot().failedCount, 1);
  assert.equal(harness.worker.getSnapshot().processedCount, 1);
});

test('worker stop is idempotent and clears the timer', async () => {
  const harness = createHarness({ queuedMessages: [] });

  await harness.worker.start();
  await harness.worker.stop();
  await harness.worker.stop();

  assert.equal(harness.calls.clearIntervals.length, 1);
  assert.equal(harness.worker.getSnapshot().isRunning, false);
  assert.equal(harness.worker.getSnapshot().hasTimer, false);
});

test('missing central message client configuration keeps the session safe', async () => {
  const harness = createHarness({
    createMessageClient: () => {
      const error = new Error('WHATSAPP_ENGINE_INTERNAL_TOKEN is not configured.');
      error.code = 'WHATSAPP_ENGINE_INTERNAL_TOKEN_MISSING';
      throw error;
    },
  });

  await harness.worker.start();

  assert.equal(harness.calls.createLaravelClient.length, 0);
  assert.equal(harness.worker.getSnapshot().isRunning, false);
  assert.equal(harness.worker.getSnapshot().lastError.code, 'WHATSAPP_ENGINE_INTERNAL_TOKEN_MISSING');
});

test('legacy fallback still works when only resolveApiToken is available', async () => {
  const harness = createHarness({
    createMessageClient: null,
    apiToken: 'legacy-session-token',
    queuedMessages: [],
  });

  await harness.worker.start();

  assert.equal(harness.calls.resolveApiToken, 1);
  assert.deepEqual(harness.calls.createLaravelClient, ['legacy-session-token']);
});

test('snapshot never exposes apiToken or whatsapp client', async () => {
  const harness = createHarness({ queuedMessages: [] });

  await harness.worker.start();
  const snapshot = harness.worker.getSnapshot();

  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'apiToken'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(snapshot, 'client'), false);
});