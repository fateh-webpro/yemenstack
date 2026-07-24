const test = require('node:test');
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const Module = require('node:module');
const path = require('node:path');

const targetPath = path.resolve(__dirname, '../src/createManagedWhatsappClient.js');

const loadWithMocks = () => {
  const qrcodeCalls = [];
  const loggerCalls = [];
  const clientInstances = [];

  class FakeClient extends EventEmitter {
    constructor(options) {
      super();
      this.options = options;
      this.initializeCalls = 0;
      this.destroyCalls = 0;
      this.sendMessageCalls = [];
      clientInstances.push(this);
    }

    async initialize() {
      this.initializeCalls += 1;
    }

    async destroy() {
      this.destroyCalls += 1;
    }

    async sendMessage(...args) {
      this.sendMessageCalls.push(args);
      return { ok: true, args };
    }
  }

  class FakeLocalAuth {
    constructor(options) {
      this.options = options;
    }
  }

  const originalLoad = Module._load;
  delete require.cache[targetPath];

  Module._load = function patchedLoad(request, parent, isMain) {
    if (request === 'whatsapp-web.js') {
      return {
        Client: FakeClient,
        LocalAuth: FakeLocalAuth,
      };
    }

    if (request === 'qrcode-terminal') {
      return {
        generate(qr, options) {
          qrcodeCalls.push({ qr, options });
        },
      };
    }

    if (request === './config' && parent && parent.filename === targetPath) {
      return {
        config: {
          whatsappHeadless: true,
          whatsappChromePath: '',
          whatsappQrTerminalSmall: true,
        },
      };
    }

    if (request === './logger' && parent && parent.filename === targetPath) {
      return {
        info(message, context) {
          loggerCalls.push({ level: 'info', message, context });
        },
        warn(message, context) {
          loggerCalls.push({ level: 'warn', message, context });
        },
        error(message, context) {
          loggerCalls.push({ level: 'error', message, context });
        },
      };
    }

    return originalLoad.apply(this, arguments);
  };

  const moduleExports = require(targetPath);
  Module._load = originalLoad;

  return {
    ...moduleExports,
    qrcodeCalls,
    loggerCalls,
    clientInstances,
  };
};

test('createManagedWhatsappClient returns the real client interface including sendMessage', async () => {
  const harness = loadWithMocks();
  const client = harness.createManagedWhatsappClient({
    accountId: 1,
    sessionName: 'wa_session_1',
    generation: 4,
  });

  assert.equal(typeof client.initialize, 'function');
  assert.equal(typeof client.destroy, 'function');
  assert.equal(typeof client.sendMessage, 'function');

  await client.initialize();
  await client.sendMessage('123@c.us', 'hello');
  await client.destroy();

  assert.equal(harness.clientInstances.length, 1);
  assert.equal(harness.clientInstances[0].initializeCalls, 1);
  assert.equal(harness.clientInstances[0].destroyCalls, 1);
  assert.deepEqual(harness.clientInstances[0].sendMessageCalls[0], ['123@c.us', 'hello']);
});

test('managed client forwards callbacks and keeps qr raw out of logs', async () => {
  const harness = loadWithMocks();
  const callbackCalls = [];
  const client = harness.createManagedWhatsappClient({
    accountId: 9,
    sessionName: 'wa_session_9',
    generation: 2,
  }, {
    onQr(qr) {
      callbackCalls.push({ type: 'qr', qr });
    },
    onAuthenticated() {
      callbackCalls.push({ type: 'authenticated' });
    },
    onReady() {
      callbackCalls.push({ type: 'ready' });
    },
    onDisconnected(reason) {
      callbackCalls.push({ type: 'disconnected', reason });
    },
    onError(error) {
      callbackCalls.push({ type: 'error', message: error.message });
    },
    onStateChanged(state) {
      callbackCalls.push({ type: 'state', state });
    },
    onLoadingScreen(percent, message) {
      callbackCalls.push({ type: 'loading', percent, message });
    },
  });

  client.emit('qr', 'RAW-QR-CONTENT');
  client.emit('authenticated');
  client.emit('change_state', 'CONNECTED');
  client.emit('loading_screen', 80, 'Loading chats');
  client.emit('ready');
  client.emit('disconnected', 'network');
  client.emit('auth_failure', 'Bad auth');
  client.emit('error', Object.assign(new Error('socket failed'), { code: 'SOCKET_FAILED' }));

  assert.equal(callbackCalls.some((entry) => entry.type === 'qr' && entry.qr === 'RAW-QR-CONTENT'), true);
  assert.equal(callbackCalls.some((entry) => entry.type === 'authenticated'), true);
  assert.equal(callbackCalls.some((entry) => entry.type === 'state' && entry.state === 'CONNECTED'), true);
  assert.equal(callbackCalls.some((entry) => entry.type === 'loading' && entry.percent === 80), true);
  assert.equal(callbackCalls.some((entry) => entry.type === 'ready'), true);
  assert.equal(callbackCalls.some((entry) => entry.type === 'disconnected' && entry.reason === 'network'), true);
  assert.equal(callbackCalls.some((entry) => entry.type === 'error' && entry.message === 'Bad auth'), true);
  assert.equal(callbackCalls.some((entry) => entry.type === 'error' && entry.message === 'socket failed'), true);
  assert.equal(harness.qrcodeCalls.length, 1);
  assert.equal(harness.qrcodeCalls[0].qr, 'RAW-QR-CONTENT');
  assert.equal(harness.loggerCalls.some((entry) => JSON.stringify(entry).includes('RAW-QR-CONTENT')), false);
  assert.equal(harness.loggerCalls.some((entry) => entry.message === 'Managed WhatsApp session state changed.'), true);
  assert.equal(harness.loggerCalls.some((entry) => entry.message === 'Managed WhatsApp session loading screen update.'), true);
});