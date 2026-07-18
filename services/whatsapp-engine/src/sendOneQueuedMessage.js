const path = require('path');
const { Client, LocalAuth } = require('whatsapp-web.js');
const { config } = require('./config');
const logger = require('./logger');
const { fetchQueuedMessages } = require('./laravelClient');
const { normalizeRecipient, getBodyPreview, sendQueuedMessage } = require('./realMessageSender');

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
        const result = await sendQueuedMessage(client, message);
        await closeClient();
        resolve(result);
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