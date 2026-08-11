const test = require('node:test');
const assert = require('node:assert/strict');
const { SessionMessageWorker } = require('../src/sessionMessageWorker');
const { config } = require('../src/config');

const createHarness = (options = {}) => {
  const calls = {
    createMessageClient: [],
    resolveApiToken: 0,
    createLaravelClient: [],
    fetchPendingMessages: [],
    claimMessage: [],
    fetchQueuedMessages: [],
    deferMessage: [],
    markMessageSent: [],
    markMessageFailed: [],
    getNumberId: [],
    sendMessage: [],
    intervals: [],
    clearIntervals: [],
    logger: [],
  };

  let ready = options.ready ?? true;
  let automaticSendingEnabled = options.automaticSendingEnabled ?? true;
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

  const queuedMessages = options.queuedMessages || [];
  const manualQueuedMessages = options.manualQueuedMessages || queuedMessages;
  const automaticPendingMessages = options.pendingMessages || [];
  const manualPendingMessages = options.manualPendingMessages || [];

  const fakeLaravelClient = options.laravelMessageClient || {
    async fetchPendingMessages(limit, options = {}) {
      calls.fetchPendingMessages.push({ limit, options });
      return {
        success: true,
        data: options.mode === 'manual' ? manualPendingMessages : automaticPendingMessages,
        meta: { limit },
      };
    },
    async claimMessage(messageId, payload = {}) {
      calls.claimMessage.push({ messageId, payload });
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
    async fetchQueuedMessages(limit, options = {}) {
      calls.fetchQueuedMessages.push({ limit, options });
      return {
        success: true,
        data: options.mode === 'manual' ? manualQueuedMessages : queuedMessages,
      };
    },
    async deferMessage(messageId, payload) {
      calls.deferMessage.push({ messageId, payload });
      return { success: true, data: { id: messageId, status: 'pending' } };
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
    getContext: () => ({ automaticSendingEnabled }),
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
    setAutomaticSendingEnabled(value) {
      automaticSendingEnabled = value;
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
    queuedMessages: [],
  });

  await harness.worker.start();

  assert.deepEqual(harness.calls.createMessageClient, [{ accountId: 701, sessionName: 'wa_worker_701' }]);
  assert.deepEqual(harness.calls.fetchQueuedMessages, [{ limit: 1, options: { mode: 'automatic' } }]);
  assert.deepEqual(harness.calls.fetchPendingMessages, [{ limit: 1, options: { mode: 'automatic' } }]);
  assert.deepEqual(harness.calls.claimMessage, [{ messageId: 1, payload: { mode: 'automatic' } }]);
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
    async fetchQueuedMessages() {
      return new Promise((resolve) => {
        release = () => resolve({ success: true, data: [] });
      });
    },
    async fetchPendingMessages() {
      throw new Error('unexpected');
    },
    async claimMessage() {
      throw new Error('unexpected');
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

test('manual mode does not claim pending messages automatically without a manual request', async () => {
  const harness = createHarness({
    automaticSendingEnabled: false,
    pendingMessages: [{ id: 31, recipient: '967700000031', body: 'pending manual', status: 'pending' }],
    manualPendingMessages: [],
    queuedMessages: [],
  });

  await harness.worker.start();

  assert.deepEqual(harness.calls.fetchQueuedMessages, [{ limit: 1, options: { mode: 'manual' } }]);
  assert.deepEqual(harness.calls.fetchPendingMessages, [{ limit: 1, options: { mode: 'manual' } }]);
  assert.equal(harness.calls.claimMessage.length, 0);
  assert.equal(harness.calls.sendMessage.length, 0);
});
test('worker reads the latest automatic sending mode from context on each cycle', async () => {
  const harness = createHarness({
    automaticSendingEnabled: true,
    queuedMessages: [],
    pendingMessages: [],
    manualQueuedMessages: [],
    manualPendingMessages: [],
  });

  await harness.worker.start();
  assert.deepEqual(harness.calls.fetchQueuedMessages[0], { limit: 1, options: { mode: 'automatic' } });

  harness.setAutomaticSendingEnabled(false);
  harness.worker.nextAutomaticSendNotBefore = null;

  await harness.worker.runCycle();

  assert.deepEqual(harness.calls.fetchQueuedMessages.at(-1), { limit: 1, options: { mode: 'manual' } });
  assert.deepEqual(harness.calls.fetchPendingMessages.at(-1), { limit: 1, options: { mode: 'manual' } });
  assert.equal(harness.worker.getSnapshot().sendingMode, 'manual');
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

test('recoverable session errors keep the queued message deferred and stop repeated sends during recovery', async () => {
  let recovering = false;
  const recoverableErrorCalls = [];
  const harness = createHarness({
    queuedMessages: [{ id: 21, recipient: '967700000021', body: 'recover me' }],
    whatsappClient: {
      async getNumberId(recipient) {
        harness.calls.getNumberId.push(recipient);
        return { _serialized: recipient + '@c.us' };
      },
      async sendMessage() {
        throw new Error('Attempted to use detached Frame');
      },
    },
  });

  harness.worker.isRecovering = () => recovering;
  harness.worker.onRecoverableError = async (metadata) => {
    recovering = true;
    recoverableErrorCalls.push(metadata);
    return true;
  };

  await harness.worker.start();

  assert.equal(harness.calls.deferMessage.length, 1);
  assert.equal(harness.calls.markMessageFailed.length, 0);
  assert.equal(harness.calls.markMessageSent.length, 0);
  assert.equal(harness.worker.getSnapshot().processedCount, 1);
  assert.equal(harness.worker.getSnapshot().failedCount, 0);
  assert.equal(recoverableErrorCalls.length, 1);
  assert.equal(recoverableErrorCalls[0].messageId, 21);
  assert.equal(recoverableErrorCalls[0].stage, 'send_message');

  await harness.worker.runCycle();
  assert.equal(harness.calls.getNumberId.length, 1);
});

test('automatic mode waits before the next message after a successful send', async () => {
  const originalMin = config.whatsappSendDelayMinMs;
  const originalMax = config.whatsappSendDelayMaxMs;
  config.whatsappSendDelayMinMs = 15000;
  config.whatsappSendDelayMaxMs = 15000;

  try {
    const harness = createHarness({
      queuedMessages: [{ id: 41, recipient: '967700000041', body: 'first send' }],
    });

    await harness.worker.start();

    harness.worker.nextAutomaticSendNotBefore = Date.now() + 15000;
    const firstFetchCount = harness.calls.fetchQueuedMessages.length;
    const firstSendCount = harness.calls.sendMessage.length;

    await harness.worker.runCycle();

    assert.equal(harness.calls.fetchQueuedMessages.length, firstFetchCount);
    assert.equal(harness.calls.sendMessage.length, firstSendCount);
    assert.equal(typeof harness.worker.getSnapshot().nextAutomaticSendNotBefore, 'number');
  } finally {
    config.whatsappSendDelayMinMs = originalMin;
    config.whatsappSendDelayMaxMs = originalMax;
  }
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
const loadConfigModuleWithEnv = (envOverrides) => {
  const originalEnv = {
    WHATSAPP_SEND_DELAY_MIN_MS: process.env.WHATSAPP_SEND_DELAY_MIN_MS,
    WHATSAPP_SEND_DELAY_MAX_MS: process.env.WHATSAPP_SEND_DELAY_MAX_MS,
  };

  if (Object.prototype.hasOwnProperty.call(envOverrides, 'WHATSAPP_SEND_DELAY_MIN_MS')) {
    process.env.WHATSAPP_SEND_DELAY_MIN_MS = envOverrides.WHATSAPP_SEND_DELAY_MIN_MS;
  } else {
    delete process.env.WHATSAPP_SEND_DELAY_MIN_MS;
  }

  if (Object.prototype.hasOwnProperty.call(envOverrides, 'WHATSAPP_SEND_DELAY_MAX_MS')) {
    process.env.WHATSAPP_SEND_DELAY_MAX_MS = envOverrides.WHATSAPP_SEND_DELAY_MAX_MS;
  } else {
    delete process.env.WHATSAPP_SEND_DELAY_MAX_MS;
  }

  delete require.cache[require.resolve('../src/config')];
  const freshModule = require('../src/config');

  if (originalEnv.WHATSAPP_SEND_DELAY_MIN_MS === undefined) {
    delete process.env.WHATSAPP_SEND_DELAY_MIN_MS;
  } else {
    process.env.WHATSAPP_SEND_DELAY_MIN_MS = originalEnv.WHATSAPP_SEND_DELAY_MIN_MS;
  }

  if (originalEnv.WHATSAPP_SEND_DELAY_MAX_MS === undefined) {
    delete process.env.WHATSAPP_SEND_DELAY_MAX_MS;
  } else {
    process.env.WHATSAPP_SEND_DELAY_MAX_MS = originalEnv.WHATSAPP_SEND_DELAY_MAX_MS;
  }

  delete require.cache[require.resolve('../src/config')];
  require('../src/config');

  return freshModule;
};

test('manual mode leaves legacy queued messages untouched when manual send was not requested', async () => {
  const harness = createHarness({
    automaticSendingEnabled: false,
    queuedMessages: [{ id: 51, recipient: '967700000051', body: 'legacy queued automatic' }],
    manualQueuedMessages: [],
    manualPendingMessages: [],
  });

  await harness.worker.start();

  assert.deepEqual(harness.calls.fetchQueuedMessages, [{ limit: 1, options: { mode: 'manual' } }]);
  assert.deepEqual(harness.calls.fetchPendingMessages, [{ limit: 1, options: { mode: 'manual' } }]);
  assert.equal(harness.calls.claimMessage.length, 0);
  assert.equal(harness.calls.sendMessage.length, 0);
  assert.equal(harness.calls.markMessageSent.length, 0);
  assert.equal(harness.calls.markMessageFailed.length, 0);
});


test('manual mode can reclaim a deferred pending message that was explicitly requested', async () => {
  const harness = createHarness({
    automaticSendingEnabled: false,
    queuedMessages: [],
    manualQueuedMessages: [],
    manualPendingMessages: [{ id: 71, recipient: '967700000071', body: 'manual deferred', status: 'pending' }],
  });

  await harness.worker.start();

  assert.deepEqual(harness.calls.fetchPendingMessages, [{ limit: 1, options: { mode: 'manual' } }]);
  assert.deepEqual(harness.calls.claimMessage, [{ messageId: 71, payload: { mode: 'manual' } }]);
  assert.equal(harness.calls.sendMessage.length, 1);
  assert.equal(harness.calls.markMessageSent.length, 1);
});
test('automatic mode does not send another message after the server disables automatic claims during the wait window', async () => {
  const harness = createHarness({
    queuedMessages: [{ id: 61, recipient: '967700000061', body: 'first automatic message' }],
  });

  await harness.worker.start();
  assert.equal(harness.calls.sendMessage.length, 1);

  harness.worker.nextAutomaticSendNotBefore = Date.now() - 1;
  harness.laravelMessageClient = {
    async fetchQueuedMessages(limit, options = {}) {
      harness.calls.fetchQueuedMessages.push({ limit, options });
      return { success: true, data: [] };
    },
    async fetchPendingMessages(limit, options = {}) {
      harness.calls.fetchPendingMessages.push({ limit, options });
      return { success: true, data: [{ id: 62, recipient: '967700000062', body: 'blocked pending' }] };
    },
    async claimMessage(messageId, payload = {}) {
      harness.calls.claimMessage.push({ messageId, payload });
      return { success: true, data: null };
    },
    async markMessageSent() {
      throw new Error('unexpected');
    },
    async markMessageFailed() {
      throw new Error('unexpected');
    },
  };
  harness.worker.laravelMessageClient = harness.laravelMessageClient;

  await harness.worker.runCycle();

  assert.equal(harness.calls.sendMessage.length, 1);
  assert.deepEqual(harness.calls.claimMessage.at(-1), { messageId: 62, payload: { mode: 'automatic' } });
});

test('send delay configuration stays at or above 15000ms for invalid values', async () => {
  const reversed = loadConfigModuleWithEnv({
    WHATSAPP_SEND_DELAY_MIN_MS: '45000',
    WHATSAPP_SEND_DELAY_MAX_MS: '30000',
  });
  assert.equal(reversed.config.whatsappSendDelayMinMs, 15000);
  assert.equal(reversed.config.whatsappSendDelayMaxMs, 30000);

  const belowMinimum = loadConfigModuleWithEnv({
    WHATSAPP_SEND_DELAY_MIN_MS: '1',
    WHATSAPP_SEND_DELAY_MAX_MS: '14999',
  });
  assert.equal(belowMinimum.config.whatsappSendDelayMinMs, 15000);
  assert.equal(belowMinimum.config.whatsappSendDelayMaxMs, 15000);

  const nonNumeric = loadConfigModuleWithEnv({
    WHATSAPP_SEND_DELAY_MIN_MS: 'abc',
    WHATSAPP_SEND_DELAY_MAX_MS: '',
  });
  assert.equal(nonNumeric.config.whatsappSendDelayMinMs, 15000);
  assert.equal(nonNumeric.config.whatsappSendDelayMaxMs, 30000);
});