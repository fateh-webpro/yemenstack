const { config } = require('./config');
const logger = require('./logger');
const { createLaravelClient } = require('./laravelClient');

const getBodyPreview = (body) => {
  if (!body) {
    return '';
  }

  return body.length > 80 ? `${body.slice(0, 80)}...` : body;
};

const pollPendingMessagesWithClient = async (laravelMessageClient, options = {}) => {
  const activeLogger = options.logger || logger;
  const limit = options.limit || config.fetchLimit;
  const service = options.service || 'whatsapp-gateway';

  const payload = await laravelMessageClient.fetchPendingMessages(limit);
  const messages = Array.isArray(payload?.data) ? payload.data : [];

  activeLogger.info('Pending messages fetched', {
    service,
    count: messages.length,
    limit: payload?.meta?.limit ?? limit,
  });

  for (const message of messages) {
    activeLogger.info('Pending message', {
      service,
      id: message.id,
      recipient: message.recipient,
      status: message.status,
      message_type: message.message_type,
      body_preview: getBodyPreview(message.body),
    });

    try {
      const claimPayload = await laravelMessageClient.claimMessage(message.id);
      const claimData = claimPayload?.data ?? {};

      activeLogger.info('Message claimed', {
        service,
        id: claimData.message_id ?? message.id,
        status: claimData.status,
        attempt_id: claimData.attempt_id,
        attempt_number: claimData.attempt_number,
      });
    } catch (error) {
      activeLogger.error('Message claim failed.', {
        service,
        id: message.id,
        code: error.code || 'unknown_error',
        status: error.status || null,
        message: error.message,
      });
    }
  }

  return payload;
};

const pollPendingMessages = async () => {
  if (!config.engineApiToken) {
    logger.warn('ENGINE_API_TOKEN is not configured; skipping pending messages poll.', {
      service: 'whatsapp-gateway',
      status: 'skipped',
    });

    return { success: false, skipped: true, reason: 'missing_token' };
  }

  return pollPendingMessagesWithClient(
    createLaravelClient({ apiToken: config.engineApiToken }),
    {
      logger,
      limit: config.fetchLimit,
      service: 'whatsapp-gateway',
    },
  );
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
  pollPendingMessagesWithClient,
};