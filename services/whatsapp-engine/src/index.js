const path = require('path');
const qrcode = require('qrcode-terminal');
const { Client, LocalAuth } = require('whatsapp-web.js');
const { config, getPublicConfig } = require('./config');
const logger = require('./logger');
const { pollPendingMessages } = require('./pendingMessages');
const { fetchQueuedMessages, updateWhatsappAccountStatus } = require('./laravelClient');
const { normalizeRecipient, sendQueuedMessage } = require('./realMessageSender');

let client = null;
let isReady = false;
let isShuttingDown = false;
let pollTimer = null;
let isCycleRunning = false;

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

const shouldAllowRecipient = (message) => {
  if (!config.whatsappTestRecipient) {
    return true;
  }

  return normalizeRecipient(message?.recipient) === normalizeRecipient(config.whatsappTestRecipient);
};

const processQueuedMessages = async () => {
  const payload = await fetchQueuedMessages(config.whatsappSendLimit);
  const messages = Array.isArray(payload?.data) ? payload.data : [];

  logger.info('Queued messages fetched for engine cycle.', {
    service: 'whatsapp-gateway',
    count: messages.length,
    limit: config.whatsappSendLimit,
  });

  if (!config.enableRealWhatsappSend) {
    logger.info('Real WhatsApp send is disabled; queued messages will not be sent.', {
      service: 'whatsapp-gateway',
      enabled: false,
      limit: config.whatsappSendLimit,
    });

    return payload;
  }

  for (const message of messages.slice(0, config.whatsappSendLimit)) {
    if (!shouldAllowRecipient(message)) {
      logger.warn('Queued message recipient is blocked by WHATSAPP_TEST_RECIPIENT.', {
        service: 'whatsapp-gateway',
        message_id: message.id,
        recipient: message.recipient,
        allowed_recipient: config.whatsappTestRecipient,
      });

      continue;
    }

    await sendQueuedMessage(client, message);
  }

  return payload;
};

const runPollCycle = async () => {
  if (!isReady || isShuttingDown) {
    return;
  }

  if (isCycleRunning) {
    logger.warn('Previous engine cycle is still running; skipping this interval.', {
      service: 'whatsapp-gateway',
      interval_ms: config.pollIntervalMs,
    });

    return;
  }

  isCycleRunning = true;

  logger.info('Engine heartbeat', {
    service: 'whatsapp-gateway',
    status: 'running',
    ready: isReady,
    real_send_enabled: config.enableRealWhatsappSend,
    send_limit: config.whatsappSendLimit,
  });

  try {
    await pollPendingMessages();
  } catch (error) {
    logger.error('Pending messages poll failed.', {
      service: 'whatsapp-gateway',
      code: error.code || 'unknown_error',
      status: error.status || null,
      message: error.message,
    });
  }

  try {
    await processQueuedMessages();
  } catch (error) {
    logger.error('Queued messages process failed.', {
      service: 'whatsapp-gateway',
      code: error.code || 'unknown_error',
      status: error.status || null,
      message: error.message,
    });
  }

  isCycleRunning = false;
};

const startPolling = () => {
  if (pollTimer) {
    return;
  }

  logger.info('Starting WhatsApp engine poll loop after client ready.', {
    service: 'whatsapp-gateway',
    poll_interval_ms: config.pollIntervalMs,
    real_send_enabled: config.enableRealWhatsappSend,
    send_limit: config.whatsappSendLimit,
    test_recipient: config.whatsappTestRecipient || null,
  });

  void runPollCycle();

  pollTimer = setInterval(() => {
    void runPollCycle();
  }, config.pollIntervalMs);
};

const stopPolling = () => {
  if (!pollTimer) {
    return;
  }

  clearInterval(pollTimer);
  pollTimer = null;
};

const shutdown = async (signal) => {
  if (isShuttingDown) {
    return;
  }

  isShuttingDown = true;
  stopPolling();

  logger.warn('Stopping Yemen Stack WhatsApp engine.', {
    service: 'whatsapp-gateway',
    signal,
  });

  await updateStatus('disconnected', {
    note: `Engine stopped by ${signal}.`,
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

const startEngine = async () => {
  if (!config.engineApiToken) {
    logger.warn('ENGINE_API_TOKEN is not configured; Yemen Stack WhatsApp engine will not start.', {
      service: 'whatsapp-gateway',
      status: 'skipped',
    });

    return;
  }

  logger.info('Starting Yemen Stack WhatsApp engine.', {
    platform: 'Yemen Stack',
    service: 'whatsapp-gateway',
    mode: 'background-engine',
  });

  logger.info('Engine configuration loaded.', getPublicConfig());

  await updateStatus('connecting');

  client = new Client({
    authStrategy: new LocalAuth({
      clientId: config.whatsappSessionId,
      dataPath: path.join(__dirname, '..', '.wwebjs_auth'),
    }),
    puppeteer: buildPuppeteerOptions(),
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

    startPolling();
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
    isReady = false;
    stopPolling();

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

  process.on('SIGINT', () => {
    void shutdown('SIGINT');
  });

  process.on('SIGTERM', () => {
    void shutdown('SIGTERM');
  });

  await client.initialize();
};

if (require.main === module) {
  startEngine().catch(async (error) => {
    logger.error('WhatsApp engine failed to start.', {
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
  startEngine,
};