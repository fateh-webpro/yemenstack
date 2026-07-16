const { config, getPublicConfig } = require('./config');
const logger = require('./logger');
const { pollPendingMessages } = require('./pendingMessages');

logger.info('Starting Yemen Stack service engine.', {
  platform: 'Yemen Stack',
  service: 'whatsapp-gateway',
  mode: 'bootstrap',
});

logger.info('Engine configuration loaded.', getPublicConfig());
logger.info('WhatsApp Gateway engine is running in idle mode.', {
  platform: 'Yemen Stack',
  service: 'whatsapp-gateway',
  note: 'WhatsApp integration is not enabled yet',
});

const runPollCycle = async () => {
  logger.info('Engine heartbeat', {
    service: 'whatsapp-gateway',
    status: 'idle',
    note: 'WhatsApp integration is not enabled yet',
  });

  if (!config.engineApiToken) {
    logger.warn('ENGINE_API_TOKEN is not configured; skipping pending messages poll.', {
      service: 'whatsapp-gateway',
      status: 'skipped',
    });

    return;
  }

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
};

setInterval(() => {
  void runPollCycle();
}, config.pollIntervalMs);