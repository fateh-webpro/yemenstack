const test = require('node:test');
const assert = require('node:assert/strict');
const { sendQueuedMessage, calculateTypingDelayMs, buildTypingChatCandidates } = require('../src/realMessageSender');
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
  recipient: '966501615360',
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

const createDefaultClient = (overrides = {}) => ({
  async getNumberId(recipient) {
    return { _serialized: `${recipient}@c.us` };
  },
  async getChatById(chatId) {
    return {
      async sendStateTyping() {},
      async clearState() {},
      id: chatId,
    };
  },
  async sendMessage(chatId, body) {
    return { id: { _serialized: 'wamid.default' }, chatId, body };
  },
  ...overrides,
});

test('typing indicator can be disabled without extra delay before send', async () => {
  const order = [];
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  const waits = [];
  const client = createDefaultClient({
    async getNumberId(recipient) {
      order.push(`getNumberId:${recipient}`);
      return { _serialized: `${recipient}@lid` };
    },
    async getChatById() {
      order.push('getChatById');
      throw new Error('should not be called when disabled');
    },
    async sendMessage(chatId, body) {
      order.push(`sendMessage:${chatId}:${body}`);
      return { id: { _serialized: 'wamid.typing.disabled' } };
    },
  });

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

test('resolved_id @lid is tried first and sendMessage still uses the c.us chatId', async () => {
  const order = [];
  const waits = [];
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  const lidChat = {
    async sendStateTyping() {
      order.push('sendStateTyping:lid');
    },
    async clearState() {
      order.push('clearState:lid');
    },
  };
  const client = createDefaultClient({
    async getNumberId(recipient) {
      order.push(`getNumberId:${recipient}`);
      return { _serialized: '148494836846671@lid' };
    },
    async getChatById(chatId) {
      order.push(`getChatById:${chatId}`);
      return chatId === '148494836846671@lid' ? lidChat : null;
    },
    async sendMessage(chatId, body) {
      order.push(`sendMessage:${chatId}:${body}`);
      return { id: { _serialized: 'wamid.typing.lid' } };
    },
  });

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
    'getNumberId:966501615360',
    'getChatById:148494836846671@lid',
    'sendStateTyping:lid',
    'sendMessage:966501615360@c.us:test body',
    'clearState:lid',
  ]);
  assert.deepEqual(waits, [3500, 0]);
  assert.equal(loggerHarness.calls.some((entry) => entry.level === 'info' && entry.message === 'WhatsApp typing indicator started before send.' && entry.context?.typing_chat_id === '148494836846671@lid'), true);
});

test('non-recoverable lid failure falls back to c.us and waits once only', async () => {
  const order = [];
  const waits = [];
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  const chatCus = {
    async sendStateTyping() {
      order.push('sendStateTyping:cus');
    },
    async clearState() {
      order.push('clearState:cus');
    },
  };
  const client = createDefaultClient({
    async getNumberId() {
      return { _serialized: '148494836846671@lid' };
    },
    async getChatById(chatId) {
      order.push(`getChatById:${chatId}`);
      if (chatId === '148494836846671@lid') {
        throw new Error('typing failed for lid');
      }
      return chatCus;
    },
    async sendMessage(chatId) {
      order.push(`sendMessage:${chatId}`);
      return { id: { _serialized: 'wamid.typing.cus' } };
    },
  });

  const result = await sendQueuedMessage(client, createMessage(), {
    accountId: 44,
    logger: loggerHarness.logger,
    laravelMessageClient: messageClientHarness.client,
    typingIndicatorEnabled: true,
    typingDelayMinMs: 3000,
    typingDelayMaxMs: 3000,
    postSendDelayMs: 0,
    wait: async (ms) => {
      waits.push(ms);
    },
  });

  assert.equal(result.success, true);
  assert.deepEqual(order, [
    'getChatById:148494836846671@lid',
    'getChatById:966501615360@c.us',
    'sendStateTyping:cus',
    'sendMessage:966501615360@c.us',
    'clearState:cus',
  ]);
  assert.deepEqual(waits, [3000, 0]);
  assert.equal(loggerHarness.calls.some((entry) => entry.level === 'warn' && entry.context?.stage === 'typing_indicator_candidate' && entry.context?.typing_chat_id === '148494836846671@lid'), true);
});

test('all non-recoverable typing candidates can fail and send still succeeds once', async () => {
  const waits = [];
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  let sendCount = 0;
  const client = createDefaultClient({
    async getNumberId() {
      return { _serialized: '148494836846671@lid' };
    },
    async getChatById(chatId) {
      throw new Error(`candidate failed ${chatId}`);
    },
    async sendMessage() {
      sendCount += 1;
      return { id: { _serialized: 'wamid.typing.send.once' } };
    },
  });

  const result = await sendQueuedMessage(client, createMessage(), {
    accountId: 44,
    logger: loggerHarness.logger,
    laravelMessageClient: messageClientHarness.client,
    typingIndicatorEnabled: true,
    typingDelayMinMs: 3000,
    typingDelayMaxMs: 3000,
    postSendDelayMs: 0,
    wait: async (ms) => {
      waits.push(ms);
    },
  });

  assert.equal(result.success, true);
  assert.equal(sendCount, 1);
  assert.deepEqual(waits, [0]);
  assert.equal(messageClientHarness.calls.markMessageSent.length, 1);
  assert.equal(messageClientHarness.calls.markMessageFailed.length, 0);
  assert.equal(loggerHarness.calls.filter((entry) => entry.level === 'warn' && entry.context?.stage === 'typing_indicator_candidate').length, 2);
  assert.equal(loggerHarness.calls.some((entry) => entry.level === 'warn' && entry.message === 'All WhatsApp typing indicator candidates failed before send.'), true);
});

test('recoverable error on lid stops fallback and keeps the existing defer path', async () => {
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  let sendCalled = false;
  const attemptedCandidates = [];
  const client = createDefaultClient({
    async getNumberId() {
      return { _serialized: '148494836846671@lid' };
    },
    async getChatById(chatId) {
      attemptedCandidates.push(chatId);
      throw new Error('Target closed');
    },
    async sendMessage() {
      sendCalled = true;
      return { id: { _serialized: 'wamid.should.not.send' } };
    },
  });

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
  assert.deepEqual(attemptedCandidates, ['148494836846671@lid']);
  assert.equal(messageClientHarness.calls.deferMessage.length, 1);
  assert.equal(messageClientHarness.calls.markMessageFailed.length, 0);
  assert.equal(messageClientHarness.calls.markMessageSent.length, 0);
});

test('duplicate typing candidate ids are removed before getChatById', async () => {
  const order = [];
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
  const client = createDefaultClient({
    async getNumberId() {
      return { _serialized: '966501615360@c.us' };
    },
    async getChatById(chatId) {
      order.push(`getChatById:${chatId}`);
      return chat;
    },
    async sendMessage(chatId) {
      order.push(`sendMessage:${chatId}`);
      return { id: { _serialized: 'wamid.typing.same.id' } };
    },
  });

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
  assert.deepEqual(order, [
    'getChatById:966501615360@c.us',
    'sendStateTyping',
    'sendMessage:966501615360@c.us',
    'clearState',
  ]);
});

test('null chat candidate falls back without waiting for the failed candidate', async () => {
  const order = [];
  const waits = [];
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  const chatCus = {
    async sendStateTyping() {
      order.push('sendStateTyping:cus');
    },
    async clearState() {
      order.push('clearState:cus');
    },
  };
  const client = createDefaultClient({
    async getNumberId() {
      return { _serialized: '148494836846671@lid' };
    },
    async getChatById(chatId) {
      order.push(`getChatById:${chatId}`);
      if (chatId === '148494836846671@lid') {
        return null;
      }
      return chatCus;
    },
    async sendMessage(chatId) {
      order.push(`sendMessage:${chatId}`);
      return { id: { _serialized: 'wamid.typing.null' } };
    },
  });

  const result = await sendQueuedMessage(client, createMessage(), {
    accountId: 44,
    logger: loggerHarness.logger,
    laravelMessageClient: messageClientHarness.client,
    typingIndicatorEnabled: true,
    typingDelayMinMs: 3100,
    typingDelayMaxMs: 3100,
    postSendDelayMs: 0,
    wait: async (ms) => {
      waits.push(ms);
    },
  });

  assert.equal(result.success, true);
  assert.deepEqual(order, [
    'getChatById:148494836846671@lid',
    'getChatById:966501615360@c.us',
    'sendStateTyping:cus',
    'sendMessage:966501615360@c.us',
    'clearState:cus',
  ]);
  assert.deepEqual(waits, [3100, 0]);
});

test('candidate without sendStateTyping falls back without waiting', async () => {
  const order = [];
  const waits = [];
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  const client = createDefaultClient({
    async getNumberId() {
      return { _serialized: '148494836846671@lid' };
    },
    async getChatById(chatId) {
      order.push(`getChatById:${chatId}`);
      if (chatId === '148494836846671@lid') {
        return { clearState: async () => {} };
      }
      return {
        async sendStateTyping() {
          order.push('sendStateTyping:cus');
        },
        async clearState() {
          order.push('clearState:cus');
        },
      };
    },
    async sendMessage(chatId) {
      order.push(`sendMessage:${chatId}`);
      return { id: { _serialized: 'wamid.typing.no.method' } };
    },
  });

  const result = await sendQueuedMessage(client, createMessage(), {
    accountId: 44,
    logger: loggerHarness.logger,
    laravelMessageClient: messageClientHarness.client,
    typingIndicatorEnabled: true,
    typingDelayMinMs: 3200,
    typingDelayMaxMs: 3200,
    postSendDelayMs: 0,
    wait: async (ms) => {
      waits.push(ms);
    },
  });

  assert.equal(result.success, true);
  assert.deepEqual(order, [
    'getChatById:148494836846671@lid',
    'getChatById:966501615360@c.us',
    'sendStateTyping:cus',
    'sendMessage:966501615360@c.us',
    'clearState:cus',
  ]);
  assert.deepEqual(waits, [3200, 0]);
});

test('clearState failure only logs a warning and keeps the send successful', async () => {
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  let sendCount = 0;
  const client = createDefaultClient({
    async getNumberId() {
      return { _serialized: '148494836846671@lid' };
    },
    async getChatById() {
      return {
        async sendStateTyping() {},
        async clearState() {
          throw new Error('clear failed');
        },
      };
    },
    async sendMessage() {
      sendCount += 1;
      return { id: { _serialized: 'wamid.typing.clear.failed' } };
    },
  });

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
  assert.equal(sendCount, 1);
  assert.equal(messageClientHarness.calls.markMessageSent.length, 1);
  assert.equal(loggerHarness.calls.some((entry) => entry.level === 'warn' && entry.context?.stage === 'typing_indicator_clear'), true);
});

test('send failure still clears typing state and does not mark the message as sent', async () => {
  const loggerHarness = createLogger();
  const messageClientHarness = createLaravelMessageClient();
  let clearCalled = false;
  const client = createDefaultClient({
    async getNumberId() {
      return { _serialized: '148494836846671@lid' };
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
  });

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

test('typing chat candidate helper keeps resolved_id first and removes duplicates', async () => {
  assert.deepEqual(
    buildTypingChatCandidates('148494836846671@lid', '966501615360@c.us', '148494836846671@lid', '', null),
    ['148494836846671@lid', '966501615360@c.us'],
  );
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
