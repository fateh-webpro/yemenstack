const { config, getPublicConfig } = require('./config');
const logger = require('./logger');

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

setInterval(() => {
  logger.info('Engine heartbeat', {
    service: 'whatsapp-gateway',
    status: 'idle',
    note: 'WhatsApp integration is not enabled yet',
  });
}, config.pollIntervalMs);