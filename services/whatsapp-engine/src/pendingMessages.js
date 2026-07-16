const { config } = require('./config');
const logger = require('./logger');
const { fetchPendingMessages } = require('./laravelClient');

const getBodyPreview = (body) => {
  if (!body) {
    return '';
  }

  return body.length > 80 ? `${body.slice(0, 80)}...` : body;
};

const pollPendingMessages = async () => {
  if (!config.engineApiToken) {
    logger.warn('ENGINE_API_TOKEN is not configured; skipping pending messages poll.', {
      service: 'whatsapp-gateway',
      status: 'skipped',
    });

    return { success: false, skipped: true, reason: 'missing_token' };
  }

  const payload = await fetchPendingMessages(config.fetchLimit);
  const messages = Array.isArray(payload?.data) ? payload.data : [];

  logger.info('Pending messages fetched', {
    service: 'whatsapp-gateway',
    count: messages.length,
    limit: payload?.meta?.limit ?? config.fetchLimit,
  });

  messages.forEach((message) => {
    logger.info('Pending message', {
      service: 'whatsapp-gateway',
      id: message.id,
      recipient: message.recipient,
      status: message.status,
      message_type: message.message_type,
      body_preview: getBodyPreview(message.body),
    });
  });

  return payload;
};

if (require.main === module) {
  pollPendingMessages().catch((error) => {
    logger.error('Pending messages poll failed.', {
      service: 'whatsapp-gateway',
      code: error.code || 'unknown_error',
      status: error.status || null,
      message: error.message,
    });

    process.exitCode = 1;
  });
}

module.exports = {
  pollPendingMessages,
};