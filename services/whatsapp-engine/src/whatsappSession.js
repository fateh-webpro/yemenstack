const path = require('path');
const qrcode = require('qrcode-terminal');
const { Client, LocalAuth } = require('whatsapp-web.js');
const { config } = require('./config');
const logger = require('./logger');
const { updateWhatsappAccountStatus } = require('./laravelClient');

let client = null;
let isShuttingDown = false;
let isReady = false;

const updateStatus = async (status, extra = {}) => {
  try {
    await updateWhatsappAccountStatus(status, extra);
  } catch (error) {
    logger.error('Failed to update WhatsApp account status.', {
      service: 'whatsapp-gateway',
      status,
      code: error.code || 'unknown_error',
      http_status: error.status || null,
      message: error.message,
    });
  }
};

const resolveDisconnectStatus = (reason) => {
  const normalizedReason = String(reason || '').toLowerCase();

  if (normalizedReason.includes('logout')) {
    return 'logged_out';
  }

  return 'disconnected';
};

const isAuthTimeoutError = (error) => {
  return String(error || '').toLowerCase().includes('auth timeout');
};

const handleProcessError = async (type, error) => {
  if (isAuthTimeoutError(error) && isReady) {
    logger.warn('Ignoring auth timeout after WhatsApp client became ready.', {
      service: 'whatsapp-gateway',
      type,
      message: error instanceof Error ? error.message : String(error || 'Unknown error'),
    });

    return;
  }

  logger.error(`WhatsApp session ${type}.`, {
    service: 'whatsapp-gateway',
    message: error instanceof Error ? error.message : String(error || 'Unknown error'),
  });

  if (!isReady) {
    await updateStatus('error', {
      note: error instanceof Error ? error.message : String(error || 'Unknown error'),
    });
  }
};

const shutdown = async (signal) => {
  if (isShuttingDown) {
    return;
  }

  isShuttingDown = true;

  logger.warn('Stopping WhatsApp session.', {
    service: 'whatsapp-gateway',
    signal,
  });

  await updateStatus('disconnected', {
    note: `Session stopped by ${signal}.`,
  });

  if (client) {
    try {
      await client.destroy();
    } catch (error) {
      logger.error('Failed to destroy WhatsApp client cleanly.', {
        service: 'whatsapp-gateway',
        message: error.message,
      });
    }
  }

  process.exit(0);
};

const startWhatsappSession = async () => {
  if (!config.engineApiToken) {
    logger.warn('ENGINE_API_TOKEN is not configured; WhatsApp session will not start.', {
      service: 'whatsapp-gateway',
      status: 'skipped',
    });

    return;
  }

  logger.info('Starting WhatsApp session', {
    service: 'whatsapp-gateway',
    session_id: config.whatsappSessionId,
    headless: config.whatsappHeadless,
  });

  await updateStatus('connecting');

  const puppeteerOptions = {
    headless: config.whatsappHeadless,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  };

  if (config.whatsappChromePath) {
    puppeteerOptions.executablePath = config.whatsappChromePath;
  }

  client = new Client({
    authStrategy: new LocalAuth({
      clientId: config.whatsappSessionId,
      dataPath: path.join(__dirname, '..', '.wwebjs_auth'),
    }),
    puppeteer: puppeteerOptions,
  });

  client.on('qr', async (qr) => {
    logger.info('WhatsApp QR received', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
    });

    qrcode.generate(qr, { small: config.whatsappQrTerminalSmall });

    await updateStatus('qr_required', {
      qr_expires_at: new Date(Date.now() + 60 * 1000).toISOString(),
    });
  });

  client.on('authenticated', () => {
    logger.info('WhatsApp client authenticated', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
    });
  });

  client.on('ready', async () => {
    isReady = true;

    logger.info('WhatsApp client is ready', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
    });

    await updateStatus('connected', {
      last_seen_at: new Date().toISOString(),
    });
  });

  client.on('auth_failure', async (message) => {
    logger.error('WhatsApp authentication failure.', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
      message,
    });

    await updateStatus('error', {
      note: typeof message === 'string' ? message : 'Authentication failure.',
    });
  });

  client.on('disconnected', async (reason) => {
    const status = resolveDisconnectStatus(reason);

    logger.warn('WhatsApp client disconnected.', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
      reason,
      status,
    });

    await updateStatus(status, {
      note: `Disconnected reason: ${String(reason || 'unknown')}`,
    });
  });

  process.on('unhandledRejection', (reason) => {
    void handleProcessError('unhandled rejection', reason);
  });

  process.on('uncaughtException', (error) => {
    void handleProcessError('uncaught exception', error);
  });

  process.on('SIGINT', () => {
    void shutdown('SIGINT');
  });

  process.on('SIGTERM', () => {
    void shutdown('SIGTERM');
  });

  await client.initialize();
};

if (require.main === module) {
  startWhatsappSession().catch(async (error) => {
    logger.error('WhatsApp session failed to start.', {
      service: 'whatsapp-gateway',
      message: error.message,
    });

    await updateStatus('error', {
      note: error.message,
    });

    process.exitCode = 1;
  });
}

module.exports = {
  startWhatsappSession,
};