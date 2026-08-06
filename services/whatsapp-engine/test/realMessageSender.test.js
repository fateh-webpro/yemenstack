const test = require('node:test');
const assert = require('node:assert/strict');
const { sendQueuedMessage, calculateTypingDelayMs } = require('../src/realMessageSender');
const { config } = require('../src/config');

const createLaravelMessageClient = () => {
  const calls = {
    deferMessage: [],
    markMessageSent: [],
    markMessageFailed: [],
  };

  return {
    calls,
    client: {
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
    },
  };
};

const createLogger = () => {
  const calls = [];

  return {
    calls,
    logger: {
      info(message, context) {
        calls.push({ level: 'info', message, context });
      },
      warn(message, context) {
        calls.push({ level: 'warn', message, context });
      },
      error(message, context) {
        calls.push({ level: 'error', message, context });
      },
    },
  };
};

const createMessage = () => ({
  id: 901,
  recipient: '967700000901',
  body: 'test body',
  whatsapp_account_id: 44,
});

const loadConfigModuleWithEnv = (envOverrides) => {
  const originalEnv = {
    WHATSAPP_TYPING_INDICATOR_ENABLED: process.env.WHATSAPP_TYPING_INDICATOR_ENABLED,
    WHATSAPP_TYPING_DELAY_MIN_MS: process.env.WHATSAPP_TYPING_DELAY_MIN_MS,
    WHATSAPP_TYPING_DELAY_MAX_MS: process.env.WHATSAPP_TYPING_DELAY_MAX_MS,
  };

  for (const [key, value] of Object.entries(envOverrides)) {
    if (value === undefined) {
      delete process.env[key];
    } else {
      process.env[key] = value;
    }
  }

  delete require.cache[require.resolve('../src/config')];
  const freshModule = require('../src/config');

  for (const [key, value] of Object.entries(originalEnv)) {
    if (value === undefined) {
      delete process.env[key];
    } else {
      process.env[key] = value;
    }
  }

  delete require.cache[require.resolve('../src/config')];
  require('../src/config');

  return freshModule;
};

test('typing indicator can be disabled without extra delay before send', async () => {
  const order = [];
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  const waits = [];
  const client = {
    async getNumberId(recipient) {
      order.push(`getNumberId:${recipient}`);
      return { _serialized: `${recipient}@c.us` };
    },
    async getChatById() {
      order.push('getChatById');
      throw new Error('should not be called when disabled');
    },
    async sendMessage(chatId, body) {
      order.push(`sendMessage:${chatId}:${body}`);
      return { id: { _serialized: 'wamid.typing.disabled' } };
    },
  };

  const result = await sendQueuedMessage(client, createMessage(), {
    accountId: 44,
    logger: loggerHarness.logger,
    laravelMessageClient: messageClientHarness.client,
    typingIndicatorEnabled: false,
    postSendDelayMs: 0,
    wait: async (ms) => {
      waits.push(ms);
    },
  });

  assert.equal(result.success, true);
  assert.equal(order.some((entry) => entry === 'getChatById'), false);
  assert.deepEqual(waits, [0]);
  assert.equal(messageClientHarness.calls.markMessageSent.length, 1);
});

test('typing indicator runs before send and clearState runs in finally', async () => {
  const order = [];
  const waits = [];
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  const chat = {
    async sendStateTyping() {
      order.push('sendStateTyping');
    },
    async clearState() {
      order.push('clearState');
    },
  };
  const client = {
    async getNumberId(recipient) {
      order.push(`getNumberId:${recipient}`);
      return { _serialized: `${recipient}@c.us` };
    },
    async getChatById(chatId) {
      order.push(`getChatById:${chatId}`);
      return chat;
    },
    async sendMessage(chatId, body) {
      order.push(`sendMessage:${chatId}:${body}`);
      return { id: { _serialized: 'wamid.typing.enabled' } };
    },
  };

  const result = await sendQueuedMessage(client, createMessage(), {
    accountId: 44,
    logger: loggerHarness.logger,
    laravelMessageClient: messageClientHarness.client,
    typingIndicatorEnabled: true,
    typingDelayMinMs: 3500,
    typingDelayMaxMs: 3500,
    postSendDelayMs: 0,
    wait: async (ms) => {
      waits.push(ms);
    },
  });

  assert.equal(result.success, true);
  assert.deepEqual(order, [
    'getNumberId:967700000901',
    'getChatById:967700000901@c.us',
    'sendStateTyping',
    'sendMessage:967700000901@c.us:test body',
    'clearState',
  ]);
  assert.deepEqual(waits, [3500, 0]);
});

test('non-recoverable typing error logs a warning and send continues', async () => {
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  let clearCalled = false;
  let sendCalled = false;
  const client = {
    async getNumberId(recipient) {
      return { _serialized: `${recipient}@c.us` };
    },
    async getChatById() {
      return {
        async sendStateTyping() {
          throw new Error('typing unavailable');
        },
        async clearState() {
          clearCalled = true;
        },
      };
    },
    async sendMessage() {
      sendCalled = true;
      return { id: { _serialized: 'wamid.typing.warn' } };
    },
  };

  const result = await sendQueuedMessage(client, createMessage(), {
    accountId: 44,
    logger: loggerHarness.logger,
    laravelMessageClient: messageClientHarness.client,
    typingIndicatorEnabled: true,
    typingDelayMinMs: 3000,
    typingDelayMaxMs: 3000,
    postSendDelayMs: 0,
    wait: async () => {},
  });

  assert.equal(result.success, true);
  assert.equal(sendCalled, true);
  assert.equal(clearCalled, true);
  assert.equal(loggerHarness.calls.some((entry) => entry.level === 'warn' && entry.context?.stage === 'typing_indicator'), true);
  assert.equal(messageClientHarness.calls.markMessageSent.length, 1);
  assert.equal(messageClientHarness.calls.deferMessage.length, 0);
});

test('recoverable typing error keeps the existing defer and recovery path', async () => {
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  let sendCalled = false;
  let clearCalled = false;
  const client = {
    async getNumberId(recipient) {
      return { _serialized: `${recipient}@c.us` };
    },
    async getChatById() {
      return {
        async sendStateTyping() {
          throw new Error('Target closed');
        },
        async clearState() {
          clearCalled = true;
        },
      };
    },
    async sendMessage() {
      sendCalled = true;
      return { id: { _serialized: 'wamid.should.not.send' } };
    },
  };

  const result = await sendQueuedMessage(client, createMessage(), {
    accountId: 44,
    logger: loggerHarness.logger,
    laravelMessageClient: messageClientHarness.client,
    typingIndicatorEnabled: true,
    typingDelayMinMs: 3000,
    typingDelayMaxMs: 3000,
    postSendDelayMs: 0,
    wait: async () => {},
  });

  assert.equal(result.recoverable, true);
  assert.equal(sendCalled, false);
  assert.equal(clearCalled, true);
  assert.equal(messageClientHarness.calls.deferMessage.length, 1);
  assert.equal(messageClientHarness.calls.markMessageFailed.length, 0);
  assert.equal(messageClientHarness.calls.markMessageSent.length, 0);
});

test('send failure still clears typing state and does not mark the message as sent', async () => {
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  let clearCalled = false;
  const client = {
    async getNumberId(recipient) {
      return { _serialized: `${recipient}@c.us` };
    },
    async getChatById() {
      return {
        async sendStateTyping() {},
        async clearState() {
          clearCalled = true;
        },
      };
    },
    async sendMessage() {
      throw new Error('send failed');
    },
  };

  const result = await sendQueuedMessage(client, createMessage(), {
    accountId: 44,
    logger: loggerHarness.logger,
    laravelMessageClient: messageClientHarness.client,
    typingIndicatorEnabled: true,
    typingDelayMinMs: 3000,
    typingDelayMaxMs: 3000,
    postSendDelayMs: 0,
    wait: async () => {},
  });

  assert.equal(result.success, false);
  assert.equal(result.failed, true);
  assert.equal(clearCalled, true);
  assert.equal(messageClientHarness.calls.markMessageSent.length, 0);
  assert.equal(messageClientHarness.calls.markMessageFailed.length, 1);
});

test('typing delay helper returns a bounded value', async () => {
  assert.equal(calculateTypingDelayMs(3000, 3000, () => 0.99), 3000);
  const value = calculateTypingDelayMs(3000, 7000, () => 0.5);
  assert.equal(value >= 3000 && value <= 7000, true);
});

test('typing configuration falls back to safe defaults for invalid values', async () => {
  const reversed = loadConfigModuleWithEnv({
    WHATSAPP_TYPING_INDICATOR_ENABLED: 'true',
    WHATSAPP_TYPING_DELAY_MIN_MS: '9000',
    WHATSAPP_TYPING_DELAY_MAX_MS: '1000',
  });
  assert.equal(reversed.config.whatsappTypingIndicatorEnabled, true);
  assert.equal(reversed.config.whatsappTypingDelayMinMs, 3000);
  assert.equal(reversed.config.whatsappTypingDelayMaxMs, 7000);

  const invalid = loadConfigModuleWithEnv({
    WHATSAPP_TYPING_INDICATOR_ENABLED: 'not-bool',
    WHATSAPP_TYPING_DELAY_MIN_MS: 'abc',
    WHATSAPP_TYPING_DELAY_MAX_MS: '',
  });
  assert.equal(invalid.config.whatsappTypingIndicatorEnabled, true);
  assert.equal(invalid.config.whatsappTypingDelayMinMs, 3000);
  assert.equal(invalid.config.whatsappTypingDelayMaxMs, 7000);

  const negative = loadConfigModuleWithEnv({
    WHATSAPP_TYPING_INDICATOR_ENABLED: 'true',
    WHATSAPP_TYPING_DELAY_MIN_MS: '-1',
    WHATSAPP_TYPING_DELAY_MAX_MS: '2000',
  });
  assert.equal(negative.config.whatsappTypingDelayMinMs, 3000);
  assert.equal(negative.config.whatsappTypingDelayMaxMs, 7000);
});

test('current config exposes typing defaults', async () => {
  assert.equal(typeof config.whatsappTypingIndicatorEnabled, 'boolean');
  assert.equal(config.whatsappTypingDelayMinMs >= 0, true);
  assert.equal(config.whatsappTypingDelayMaxMs >= config.whatsappTypingDelayMinMs, true);
});