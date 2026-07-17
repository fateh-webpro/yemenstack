const { config } = require('./config');
const logger = require('./logger');
const { fetchQueuedMessages, markMessageSent } = require('./laravelClient');

const getBodyPreview = (body) => {
  if (!body) {
    return '';
  }

  return body.length > 80 ? `${body.slice(0, 80)}...` : body;
};

const processQueuedMessages = async () => {
  if (!config.engineApiToken) {
    logger.warn('ENGINE_API_TOKEN is not configured; skipping queued messages process.', {
      service: 'whatsapp-gateway',
      status: 'skipped',
    });

    return { success: false, skipped: true, reason: 'missing_token' };
  }

  const payload = await fetchQueuedMessages(config.fetchLimit);
  const messages = Array.isArray(payload?.data) ? payload.data : [];

  logger.info('Queued messages fetched', {
    service: 'whatsapp-gateway',
    count: messages.length,
    limit: payload?.meta?.limit ?? config.fetchLimit,
  });

  for (const message of messages) {
    logger.info('Queued message', {
      service: 'whatsapp-gateway',
      id: message.id,
      recipient: message.recipient,
      status: message.status,
      message_type: message.message_type,
      body_preview: getBodyPreview(message.body),
    });

    try {
      const sentPayload = await markMessageSent(message.id);
      const sentData = sentPayload?.data ?? {};

      logger.info('Message marked as sent in simulation mode', {
        service: 'whatsapp-gateway',
        id: sentData.message_id ?? message.id,
        status: sentData.status,
        external_message_id: sentData.external_message_id,
        sent_at: sentData.sent_at,
        attempt_id: sentData.attempt_id,
        attempt_status: sentData.attempt_status,
      });
    } catch (error) {
      logger.error('Queued message simulation failed.', {
        service: 'whatsapp-gateway',
        id: message.id,
        code: error.code || 'unknown_error',
        status: error.status || null,
        message: error.message,
      });
    }
  }

  return payload;
};

if (require.main === module) {
  processQueuedMessages().catch((error) => {
    logger.error('Queued messages process failed.', {
      service: 'whatsapp-gateway',
      code: error.code || 'unknown_error',
      status: error.status || null,
      message: error.message,
    });

    process.exitCode = 1;
  });
}

module.exports = {
  processQueuedMessages,
};