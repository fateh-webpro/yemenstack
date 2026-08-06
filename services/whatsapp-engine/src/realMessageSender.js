const logger = require('./logger');
const { config } = require('./config');
const { createLaravelClient } = require('./laravelClient');
const { isRecoverableSessionError, getErrorMessage } = require('./sessionRecovery');

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const postSendDelayMs = 2000;

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

const buildFailurePayload = (errorMessage, error, extra = {}) => ({
  mode: 'real',
  provider: 'whatsapp-web.js',
  error_message: errorMessage,
  error_name: error?.name ?? null,
  error_code: error?.code ?? null,
  note: 'Real WhatsApp send failed.',
  ...extra,
});

const createDefaultMessageClient = () => createLaravelClient({
  apiToken: config.engineApiToken,
});

const calculateTypingDelayMs = (
  minDelayMs = config.whatsappTypingDelayMinMs,
  maxDelayMs = config.whatsappTypingDelayMaxMs,
  randomFn = Math.random,
) => {
  if (!Number.isInteger(minDelayMs) || !Number.isInteger(maxDelayMs) || minDelayMs < 0 || maxDelayMs < minDelayMs) {
    return config.whatsappTypingDelayMinMs;
  }

  if (minDelayMs === maxDelayMs) {
    return minDelayMs;
  }

  return Math.floor(randomFn() * ((maxDelayMs - minDelayMs) + 1)) + minDelayMs;
};

const resolveTypingChat = async (client, chatId) => {
  if (typeof client.getChatById !== 'function') {
    return null;
  }

  return client.getChatById(chatId);
};

const clearTypingIndicator = async (chat) => {
  if (!chat || typeof chat.clearState !== 'function') {
    return;
  }

  await chat.clearState();
};

const sendQueuedMessage = async (client, message, options = {}) => {
  const activeLogger = options.logger || logger;
  const laravelMessageClient = options.laravelMessageClient || createDefaultMessageClient();
  const wait = options.wait || delay;
  const actualRecipient = normalizeRecipient(message?.recipient);
  const body = message?.body ?? '';
  const accountId = options.accountId ?? message?.whatsapp_account_id ?? null;
  const typingIndicatorEnabled = options.typingIndicatorEnabled ?? config.whatsappTypingIndicatorEnabled;
  const typingDelayMinMs = options.typingDelayMinMs ?? config.whatsappTypingDelayMinMs;
  const typingDelayMaxMs = options.typingDelayMaxMs ?? config.whatsappTypingDelayMaxMs;
  const postSendDelay = options.postSendDelayMs ?? postSendDelayMs;
  let sendStage = 'resolve_number';
  let typingChat = null;
  let clearTypingState = false;

  activeLogger.info('Sending queued WhatsApp message.', {
    service: 'whatsapp-gateway',
    accountId,
    message_id: message?.id,
    recipient: message?.recipient,
    body_preview: getBodyPreview(body),
    typing_indicator_enabled: Boolean(typingIndicatorEnabled),
  });

  try {
    const numberId = await client.getNumberId(actualRecipient);

    if (!numberId?._serialized) {
      const errorMessage = 'Recipient is not available on WhatsApp or could not be resolved.';
      const failedAt = new Date().toISOString();

      activeLogger.warn('WhatsApp number could not be resolved.', {
        service: 'whatsapp-gateway',
        accountId,
        message_id: message?.id,
        recipient: actualRecipient,
      });

      await laravelMessageClient.markMessageFailed(message.id, {
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

    activeLogger.info('WhatsApp number resolved', {
      service: 'whatsapp-gateway',
      accountId,
      message_id: message?.id,
      recipient: actualRecipient,
      resolved_id: numberId._serialized,
      send_chat_id: chatId,
    });

    sendStage = 'typing_indicator';

    if (typingIndicatorEnabled) {
      try {
        typingChat = await resolveTypingChat(client, chatId);
        clearTypingState = Boolean(typingChat && typeof typingChat.clearState === 'function');

        if (typingChat && typeof typingChat.sendStateTyping === 'function') {
          await typingChat.sendStateTyping();

          const typingDelayMs = calculateTypingDelayMs(
            typingDelayMinMs,
            typingDelayMaxMs,
            options.randomFn,
          );

          activeLogger.info('WhatsApp typing indicator started before send.', {
            service: 'whatsapp-gateway',
            accountId,
            message_id: message?.id,
            typingDelayMs,
          });

          await wait(typingDelayMs);
        }
      } catch (typingError) {
        if (isRecoverableSessionError(typingError)) {
          throw typingError;
        }

        activeLogger.warn('WhatsApp typing indicator failed before send.', {
          service: 'whatsapp-gateway',
          accountId,
          message_id: message?.id,
          stage: 'typing_indicator',
          message: getErrorMessage(typingError),
        });
      }
    }

    sendStage = 'send_message';

    const result = await client.sendMessage(chatId, body);
    await wait(postSendDelay);

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

      activeLogger.warn('WhatsApp send returned without message id', {
        service: 'whatsapp-gateway',
        accountId,
        message_id: message?.id,
        recipient: actualRecipient,
        resolved_id: numberId._serialized,
        send_chat_id: chatId,
      });

      activeLogger.info('Marking message as sent with fallback external id.', {
        service: 'whatsapp-gateway',
        accountId,
        message_id: message?.id,
        external_message_id: externalMessageId,
      });
    } else {
      activeLogger.info('WhatsApp send returned message id', {
        service: 'whatsapp-gateway',
        accountId,
        message_id: message?.id,
        external_message_id: externalMessageId,
        resolved_id: numberId._serialized,
        send_chat_id: chatId,
      });
    }

    sendStage = 'mark_sent';

    const sentAt = new Date().toISOString();
    const sentPayload = await laravelMessageClient.markMessageSent(message.id, {
      external_message_id: externalMessageId,
      response_payload: responsePayload,
      mode: 'real',
      provider: 'whatsapp-web.js',
      sent_at: sentAt,
    });

    activeLogger.info('Real WhatsApp send completed for queued message.', {
      service: 'whatsapp-gateway',
      accountId,
      message_id: message?.id,
      external_message_id: externalMessageId,
      sent_at: sentAt,
      status: sentPayload?.data?.status ?? 'sent',
    });

    return { success: true, data: sentPayload?.data ?? null };
  } catch (error) {
    const errorMessage = getErrorMessage(error) || 'Unknown send error';
    const failedAt = new Date().toISOString();
    const recoverable = isRecoverableSessionError(error);

    if (recoverable && sendStage !== 'mark_sent') {
      activeLogger.warn('Recoverable WhatsApp session error detected while processing a queued message.', {
        service: 'whatsapp-gateway',
        accountId,
        message_id: message?.id,
        stage: sendStage,
        message: errorMessage,
      });

      try {
        await laravelMessageClient.deferMessage(message.id, {
          error_message: errorMessage,
          response_payload: buildFailurePayload(errorMessage, error, {
            stage: sendStage,
            recoverable_connection_error: true,
            delivery_state: 'deferred_for_session_recovery',
          }),
          mode: 'real',
          provider: 'whatsapp-web.js',
          stage: sendStage,
          deferred_at: failedAt,
        });
      } catch (deferError) {
        activeLogger.error('Failed to defer queued message for session recovery.', {
          service: 'whatsapp-gateway',
          accountId,
          message_id: message?.id,
          stage: sendStage,
          message: deferError.message,
        });
      }

      return {
        success: false,
        failed: false,
        recoverable: true,
        safeToRetry: true,
        stage: sendStage,
        messageId: message.id,
        error: errorMessage,
        errorObject: error,
      };
    }

    activeLogger[recoverable ? 'warn' : 'error']('Real WhatsApp send failed for queued message.', {
      service: 'whatsapp-gateway',
      accountId,
      message_id: message?.id,
      stage: sendStage,
      recoverable,
      message: errorMessage,
    });

    try {
      await laravelMessageClient.markMessageFailed(message.id, {
        error_message: errorMessage,
        response_payload: buildFailurePayload(errorMessage, error, {
          stage: sendStage,
          recoverable_connection_error: recoverable,
          delivery_state: recoverable ? 'unknown_after_send_attempt' : 'failed_before_delivery',
        }),
        mode: 'real',
        provider: 'whatsapp-web.js',
        failed_at: failedAt,
      });
    } catch (markFailedError) {
      activeLogger.error('Failed to mark queued message as failed after real send error.', {
        service: 'whatsapp-gateway',
        accountId,
        message_id: message?.id,
        message: markFailedError.message,
      });
    }

    return {
      success: false,
      failed: true,
      recoverable,
      safeToRetry: false,
      stage: sendStage,
      messageId: message.id,
      error: errorMessage,
      errorObject: error,
    };
  } finally {
    if (clearTypingState) {
      try {
        await clearTypingIndicator(typingChat);
      } catch (clearError) {
        activeLogger.warn('WhatsApp typing indicator clearState failed after send attempt.', {
          service: 'whatsapp-gateway',
          accountId,
          message_id: message?.id,
          stage: 'typing_indicator_clear',
          message: getErrorMessage(clearError),
        });
      }
    }
  }
};

module.exports = {
  normalizeRecipient,
  getBodyPreview,
  isRecoverableWhatsappError: isRecoverableSessionError,
  calculateTypingDelayMs,
  clearTypingIndicator,
  resolveTypingChat,
  sendQueuedMessage,
};
