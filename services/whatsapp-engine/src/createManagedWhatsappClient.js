const path = require('path');
const qrcode = require('qrcode-terminal');
const { Client, LocalAuth } = require('whatsapp-web.js');
const { config } = require('./config');
const logger = require('./logger');

const buildPuppeteerOptions = () => {
  const puppeteerOptions = {
    headless: config.whatsappHeadless,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  };

  if (config.whatsappChromePath) {
    puppeteerOptions.executablePath = config.whatsappChromePath;
  }

  return puppeteerOptions;
};

const createManagedWhatsappClient = (sessionDescriptor, callbacks = {}) => {
  const client = new Client({
    authStrategy: new LocalAuth({
      clientId: sessionDescriptor.sessionName,
      dataPath: path.join(__dirname, '..', '.wwebjs_auth'),
    }),
    puppeteer: buildPuppeteerOptions(),
  });

  client.on('qr', (qr) => {
    logger.info('Managed WhatsApp QR received.', {
      accountId: sessionDescriptor.accountId,
      sessionName: sessionDescriptor.sessionName,
    });

    qrcode.generate(qr, { small: config.whatsappQrTerminalSmall });
    callbacks.onQr?.(qr);
  });

  client.on('authenticated', () => {
    callbacks.onAuthenticated?.();
  });

  client.on('ready', () => {
    callbacks.onReady?.();
  });

  client.on('disconnected', (reason) => {
    callbacks.onDisconnected?.(reason);
  });

  client.on('auth_failure', (message) => {
    callbacks.onError?.(new Error(typeof message === 'string' ? message : 'Authentication failure.'));
  });

  client.on('error', (error) => {
    callbacks.onError?.(error);
  });

  return {
    async initialize() {
      await client.initialize();
    },
    async destroy() {
      await client.destroy();
    },
  };
};

module.exports = {
  createManagedWhatsappClient,
};