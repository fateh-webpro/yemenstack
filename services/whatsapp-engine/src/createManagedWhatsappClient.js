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

const sanitizeLogMessage = (value, fallback = null) => {
  if (value === undefined || value === null || value === '') {
    return fallback;
  }

  return String(value);
};

const createManagedWhatsappClient = (sessionDescriptor, callbacks = {}) => {
  const client = new Client({
    authStrategy: new LocalAuth({
      clientId: sessionDescriptor.sessionName,
      dataPath: path.join(__dirname, '..', '.wwebjs_auth'),
    }),
    puppeteer: buildPuppeteerOptions(),
  });

  const logContext = (extra = {}) => ({
    accountId: sessionDescriptor.accountId,
    sessionName: sessionDescriptor.sessionName,
    generation: sessionDescriptor.generation ?? null,
    ...extra,
  });

  client.on('qr', (qr) => {
    logger.info('Managed WhatsApp QR received.', logContext());

    qrcode.generate(qr, { small: config.whatsappQrTerminalSmall });
    callbacks.onQr?.(qr);
  });

  client.on('authenticated', () => {
    logger.info('Managed WhatsApp session authenticated.', logContext());
    callbacks.onAuthenticated?.();
  });

  client.on('ready', () => {
    logger.info('Managed WhatsApp session ready.', logContext());
    callbacks.onReady?.();
  });

  client.on('change_state', (state) => {
    logger.info('Managed WhatsApp session state changed.', logContext({
      state: sanitizeLogMessage(state, 'unknown'),
    }));
    callbacks.onStateChanged?.(state);
  });

  client.on('loading_screen', (percent, message) => {
    logger.info('Managed WhatsApp session loading screen update.', logContext({
      percent: Number.isFinite(percent) ? percent : null,
      message: sanitizeLogMessage(message),
    }));
    callbacks.onLoadingScreen?.(percent, message);
  });

  client.on('disconnected', (reason) => {
    logger.warn('Managed WhatsApp session disconnected.', logContext({
      reason: sanitizeLogMessage(reason, 'unknown'),
    }));
    callbacks.onDisconnected?.(reason);
  });

  client.on('auth_failure', (message) => {
    const error = new Error(typeof message === 'string' ? message : 'Authentication failure.');

    logger.error('Managed WhatsApp authentication failure.', logContext({
      message: error.message,
    }));
    callbacks.onError?.(error);
  });

  client.on('error', (error) => {
    logger.error('Managed WhatsApp client emitted an error.', logContext({
      message: error?.message || String(error),
      code: error?.code || null,
    }));
    callbacks.onError?.(error);
  });

  return client;
};

module.exports = {
  createManagedWhatsappClient,
};