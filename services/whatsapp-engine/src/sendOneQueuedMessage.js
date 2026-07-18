const path = require('path');
const { Client, LocalAuth } = require('whatsapp-web.js');
const { config } = require('./config');
const logger = require('./logger');
const {
  fetchQueuedMessages,
  markMessageSent,
  markMessageFailed,
} = require('./laravelClient');

const normalizeRecipient = (value) => String(value || '').replace(/\D+/g, '');

const getBodyPreview = (body) => {
  if (!body) {
    return '';
  }

  return body.length > 80 ? `${body.slice(0, 80)}...` : body;
};

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

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

const buildResponsePayloadFromSendResult = (result) => ({
  mode: 'real',
  provider: 'whatsapp-web.js',
  whatsapp_message_id: result?.id?._serialized ?? result?.id?.id ?? null,
  from: result?.from ?? null,
  to: result?.to ?? null,
  ack: result?.ack ?? null,
  timestamp: result?.timestamp ?? null,
  has_media: result?.hasMedia ?? false,
});

const buildFailurePayload = (errorMessage, error) => ({
  mode: 'real',
  provider: 'whatsapp-web.js',
  error_message: errorMessage,
  error_name: error?.name ?? null,
  error_code: error?.code ?? null,
  note: 'Real WhatsApp send failed.',
});

const sendOneQueuedMessage = async () => {
  if (!config.enableRealWhatsappSend) {
    logger.warn('ENABLE_REAL_WHATSAPP_SEND is disabled; real WhatsApp send will not run.', {
      service: 'whatsapp-gateway',
      status: 'skipped',
    });

    return { success: false, skipped: true, reason: 'disabled' };
  }

  if (!config.engineApiToken) {
    logger.warn('ENGINE_API_TOKEN is not configured; real WhatsApp send will not run.', {
      service: 'whatsapp-gateway',
      status: 'skipped',
    });

    return { success: false, skipped: true, reason: 'missing_token' };
  }

  if (!config.whatsappTestRecipient) {
    logger.warn('WHATSAPP_TEST_RECIPIENT is not configured; real WhatsApp send will not run.', {
      service: 'whatsapp-gateway',
      status: 'skipped',
    });

    return { success: false, skipped: true, reason: 'missing_test_recipient' };
  }

  const payload = await fetchQueuedMessages(config.whatsappSendLimit);
  const messages = Array.isArray(payload?.data) ? payload.data : [];

  logger.info('Queued messages fetched for real send.', {
    service: 'whatsapp-gateway',
    count: messages.length,
    limit: config.whatsappSendLimit,
  });

  if (messages.length === 0) {
    logger.info('No queued messages available for real WhatsApp send.', {
      service: 'whatsapp-gateway',
      status: 'idle',
    });

    return { success: true, skipped: true, reason: 'no_queued_messages' };
  }

  const message = messages[0];
  const expectedRecipient = normalizeRecipient(config.whatsappTestRecipient);
  const actualRecipient = normalizeRecipient(message.recipient);

  if (!actualRecipient || actualRecipient !== expectedRecipient) {
    logger.warn('Queued message recipient does not match WHATSAPP_TEST_RECIPIENT; skipping send.', {
      service: 'whatsapp-gateway',
      message_id: message.id,
      recipient: message.recipient,
      expected_recipient: config.whatsappTestRecipient,
      body_preview: getBodyPreview(message.body),
    });

    return { success: false, skipped: true, reason: 'recipient_mismatch', messageId: message.id };
  }

  const client = new Client({
    authStrategy: new LocalAuth({
      clientId: config.whatsappSessionId,
      dataPath: path.join(__dirname, '..', '.wwebjs_auth'),
    }),
    puppeteer: buildPuppeteerOptions(),
  });

  let settled = false;

  const closeClient = async () => {
    try {
      await client.destroy();
    } catch (error) {
      logger.warn('Failed to close WhatsApp client after real send test.', {
        service: 'whatsapp-gateway',
        message: error.message,
      });
    }
  };

  return new Promise((resolve, reject) => {
    const finish = async (callback) => {
      if (settled) {
        return;
      }

      settled = true;

      try {
        await callback();
      } catch (error) {
        reject(error);
      }
    };

    client.on('qr', () => {
      void finish(async () => {
        logger.warn('QR received during send:one; an authenticated WhatsApp session is required first.', {
          service: 'whatsapp-gateway',
          session_id: config.whatsappSessionId,
        });

        await closeClient();
        resolve({ success: false, skipped: true, reason: 'qr_required' });
      });
    });

    client.on('auth_failure', (details) => {
      void finish(async () => {
        await closeClient();
        reject(new Error(typeof details === 'string' ? details : 'WhatsApp authentication failure.'));
      });
    });

    client.on('disconnected', (reason) => {
      if (settled) {
        return;
      }

      void finish(async () => {
        await closeClient();
        reject(new Error(`WhatsApp client disconnected before sending: ${String(reason || 'unknown')}`));
      });
    });

    client.on('ready', () => {
      void finish(async () => {
        const body = message.body ?? '';

        logger.info('Sending one real WhatsApp message from queued state.', {
          service: 'whatsapp-gateway',
          message_id: message.id,
          recipient: message.recipient,
          body_preview: getBodyPreview(body),
        });

        try {
          const numberId = await client.getNumberId(actualRecipient);

          if (!numberId?._serialized) {
            const errorMessage = 'Recipient is not available on WhatsApp or could not be resolved.';
            const failedAt = new Date().toISOString();

            logger.warn('WhatsApp number could not be resolved.', {
              service: 'whatsapp-gateway',
              message_id: message.id,
              recipient: actualRecipient,
            });

            await markMessageFailed(message.id, {
              error_message: errorMessage,
              response_payload: {
                mode: 'real',
                provider: 'whatsapp-web.js',
                recipient: actualRecipient,
                reason: 'number_not_resolved',
              },
              mode: 'real',
              provider: 'whatsapp-web.js',
              failed_at: failedAt,
            });

            await closeClient();
            resolve({ success: false, failed: true, messageId: message.id, error: errorMessage });
            return;
          }

          const chatId = `${actualRecipient}@c.us`;

          logger.info('WhatsApp number resolved', {
            service: 'whatsapp-gateway',
            message_id: message.id,
            recipient: actualRecipient,
            resolved_id: numberId._serialized,
            send_chat_id: chatId,
          });

          const result = await client.sendMessage(chatId, body);
          await delay(2000);

          let externalMessageId = result?.id?._serialized ?? result?.id?.id ?? null;
          let responsePayload = buildResponsePayloadFromSendResult(result);

          if (!externalMessageId) {
            externalMessageId = `real-no-id-${message.id}-${Date.now()}`;
            responsePayload = {
              mode: 'real',
              provider: 'whatsapp-web.js',
              recipient: actualRecipient,
              resolved_id: numberId._serialized,
              send_chat_id: chatId,
              warning: 'WhatsApp send completed but returned without message id.',
              result_type: typeof result,
            };

            logger.warn('WhatsApp send returned without message id', {
              service: 'whatsapp-gateway',
              message_id: message.id,
              recipient: actualRecipient,
              resolved_id: numberId._serialized,
              send_chat_id: chatId,
            });

            logger.info('Marking message as sent with fallback external id.', {
              service: 'whatsapp-gateway',
              message_id: message.id,
              external_message_id: externalMessageId,
            });
          } else {
            logger.info('WhatsApp send returned message id', {
              service: 'whatsapp-gateway',
              message_id: message.id,
              external_message_id: externalMessageId,
              resolved_id: numberId._serialized,
              send_chat_id: chatId,
            });
          }

          const sentAt = new Date().toISOString();

          const sentPayload = await markMessageSent(message.id, {
            external_message_id: externalMessageId,
            response_payload: responsePayload,
            mode: 'real',
            provider: 'whatsapp-web.js',
            sent_at: sentAt,
          });

          logger.info('Real WhatsApp send completed for one queued message.', {
            service: 'whatsapp-gateway',
            message_id: message.id,
            external_message_id: externalMessageId,
            sent_at: sentAt,
            status: sentPayload?.data?.status ?? 'sent',
          });

          await closeClient();
          resolve({ success: true, data: sentPayload?.data ?? null });
        } catch (error) {
          const errorMessage = error instanceof Error ? error.message : String(error || 'Unknown send error');
          const failedAt = new Date().toISOString();

          logger.error('Real WhatsApp send failed for queued message.', {
            service: 'whatsapp-gateway',
            message_id: message.id,
            message: errorMessage,
          });

          try {
            await markMessageFailed(message.id, {
              error_message: errorMessage,
              response_payload: buildFailurePayload(errorMessage, error),
              mode: 'real',
              provider: 'whatsapp-web.js',
              failed_at: failedAt,
            });
          } catch (markFailedError) {
            logger.error('Failed to mark queued message as failed after real send error.', {
              service: 'whatsapp-gateway',
              message_id: message.id,
              message: markFailedError.message,
            });
          }

          await closeClient();
          resolve({ success: false, failed: true, messageId: message.id, error: errorMessage });
        }
      });
    });

    client.initialize().catch((error) => {
      void finish(async () => {
        await closeClient();
        reject(error);
      });
    });
  });
};

if (require.main === module) {
  sendOneQueuedMessage()
    .then((result) => {
      if (result?.success === false && !result?.skipped) {
        process.exitCode = 1;
      }
    })
    .catch((error) => {
      logger.error('send:one failed.', {
        service: 'whatsapp-gateway',
        message: error.message,
      });

      process.exitCode = 1;
    });
}

module.exports = {
  sendOneQueuedMessage,
};