const logger = require('./logger');
const { markMessageSent, markMessageFailed } = require('./laravelClient');

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const normalizeRecipient = (value) => String(value || '').replace(/\D+/g, '');

const getBodyPreview = (body) => {
  if (!body) {
    return '';
  }

  return body.length > 80 ? `${body.slice(0, 80)}...` : body;
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

const sendQueuedMessage = async (client, message) => {
  const actualRecipient = normalizeRecipient(message?.recipient);
  const body = message?.body ?? '';

  logger.info('Sending queued WhatsApp message.', {
    service: 'whatsapp-gateway',
    message_id: message?.id,
    recipient: message?.recipient,
    body_preview: getBodyPreview(body),
  });

  try {
    const numberId = await client.getNumberId(actualRecipient);

    if (!numberId?._serialized) {
      const errorMessage = 'Recipient is not available on WhatsApp or could not be resolved.';
      const failedAt = new Date().toISOString();

      logger.warn('WhatsApp number could not be resolved.', {
        service: 'whatsapp-gateway',
        message_id: message?.id,
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

      return { success: false, failed: true, messageId: message.id, error: errorMessage };
    }

    const chatId = `${actualRecipient}@c.us`;

    logger.info('WhatsApp number resolved', {
      service: 'whatsapp-gateway',
      message_id: message?.id,
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
        message_id: message?.id,
        recipient: actualRecipient,
        resolved_id: numberId._serialized,
        send_chat_id: chatId,
      });

      logger.info('Marking message as sent with fallback external id.', {
        service: 'whatsapp-gateway',
        message_id: message?.id,
        external_message_id: externalMessageId,
      });
    } else {
      logger.info('WhatsApp send returned message id', {
        service: 'whatsapp-gateway',
        message_id: message?.id,
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

    logger.info('Real WhatsApp send completed for queued message.', {
      service: 'whatsapp-gateway',
      message_id: message?.id,
      external_message_id: externalMessageId,
      sent_at: sentAt,
      status: sentPayload?.data?.status ?? 'sent',
    });

    return { success: true, data: sentPayload?.data ?? null };
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error || 'Unknown send error');
    const failedAt = new Date().toISOString();

    logger.error('Real WhatsApp send failed for queued message.', {
      service: 'whatsapp-gateway',
      message_id: message?.id,
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
        message_id: message?.id,
        message: markFailedError.message,
      });
    }

    return { success: false, failed: true, messageId: message.id, error: errorMessage };
  }
};

module.exports = {
  normalizeRecipient,
  getBodyPreview,
  sendQueuedMessage,
};