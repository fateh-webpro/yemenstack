const { config } = require('./config');
const logger = require('./logger');
const { createLaravelClient } = require('./laravelClient');
const { pollPendingMessagesWithClient } = require('./pendingMessages');
const { normalizeRecipient, sendQueuedMessage } = require('./realMessageSender');

const sanitizeError = (error) => {
  if (!error) {
    return null;
  }

  if (error instanceof Error) {
    return {
      name: error.name,
      message: error.message,
      code: error.code || null,
    };
  }

  return {
    name: 'Error',
    message: String(error),
    code: null,
  };
};

class SessionMessageWorker {
  constructor(dependencies = {}) {
    if (dependencies.accountId === undefined || dependencies.accountId === null || dependencies.accountId === '') {
      throw new Error('accountId is required.');
    }

    if (!dependencies.sessionName) {
      throw new Error('sessionName is required.');
    }

    this.accountId = dependencies.accountId;
    this.sessionName = dependencies.sessionName;
    this.logger = dependencies.logger || logger;
    this.pollIntervalMs = dependencies.pollIntervalMs || config.pollIntervalMs;
    this.fetchLimit = dependencies.fetchLimit || config.fetchLimit;
    this.enableRealWhatsappSend = dependencies.enableRealWhatsappSend ?? config.enableRealWhatsappSend;
    this.whatsappTestRecipient = dependencies.whatsappTestRecipient ?? config.whatsappTestRecipient;
    this.getWhatsappClient = dependencies.getWhatsappClient || (() => null);
    this.isReady = dependencies.isReady || (() => false);
    this.createLaravelClient = dependencies.createLaravelClient || createLaravelClient;
    this.resolveApiToken = dependencies.resolveApiToken || null;
    this.laravelMessageClient = dependencies.laravelMessageClient || null;
    this.setInterval = dependencies.setInterval || global.setInterval;
    this.clearInterval = dependencies.clearInterval || global.clearInterval;

    this.isRunning = false;
    this.isCycleRunning = false;
    this.timer = null;
    this.lastCycleStartedAt = null;
    this.lastCycleFinishedAt = null;
    this.lastError = null;
    this.processedCount = 0;
    this.sentCount = 0;
    this.failedCount = 0;
    this.currentCyclePromise = null;
    this.cachedApiToken = null;
  }

  async start() {
    if (this.isRunning) {
      return this.getSnapshot();
    }

    if (!this.isReady()) {
      return this.getSnapshot();
    }

    try {
      await this.ensureLaravelClient();
    } catch (error) {
      this.lastError = sanitizeError(error);
      this.logger.warn('Session message worker could not start without a session API token.', {
        accountId: this.accountId,
        sessionName: this.sessionName,
        code: error.code || null,
        message: error.message,
      });

      return this.getSnapshot();
    }

    this.isRunning = true;
    this.lastError = null;

    await this.runCycle();

    if (!this.timer) {
      this.timer = this.setInterval(() => {
        void this.runCycle();
      }, this.pollIntervalMs);
    }

    return this.getSnapshot();
  }

  async stop() {
    if (this.timer) {
      this.clearInterval(this.timer);
      this.timer = null;
    }

    this.isRunning = false;
    this.currentCyclePromise = null;

    return this.getSnapshot();
  }

  runCycle() {
    if (!this.isRunning || !this.isReady()) {
      return this.getSnapshot();
    }

    if (this.currentCyclePromise) {
      return this.currentCyclePromise;
    }

    this.currentCyclePromise = (async () => {
      this.isCycleRunning = true;
      this.lastCycleStartedAt = new Date().toISOString();

      try {
        const client = this.getWhatsappClient();

        if (!client) {
          return this.getSnapshot();
        }

        const laravelMessageClient = await this.ensureLaravelClient();

        await pollPendingMessagesWithClient(laravelMessageClient, {
          logger: this.logger,
          limit: this.fetchLimit,
          service: 'whatsapp-gateway',
        });

        if (!this.enableRealWhatsappSend) {
          return this.getSnapshot();
        }

        const queuedPayload = await laravelMessageClient.fetchQueuedMessages(this.fetchLimit);
        const messages = Array.isArray(queuedPayload?.data) ? queuedPayload.data : [];

        for (const message of messages) {
          if (!this.isRunning || !this.isReady()) {
            break;
          }

          const sendResult = await this.sendClaimedMessage(message, {
            client,
            laravelMessageClient,
          });

          if (sendResult?.recoverable) {
            break;
          }
        }

        return this.getSnapshot();
      } catch (error) {
        this.lastError = sanitizeError(error);
        this.logger.error('Session message worker cycle failed.', {
          accountId: this.accountId,
          sessionName: this.sessionName,
          code: error.code || null,
          message: error.message,
        });

        return this.getSnapshot();
      } finally {
        this.isCycleRunning = false;
        this.lastCycleFinishedAt = new Date().toISOString();
        this.currentCyclePromise = null;
      }
    })();

    return this.currentCyclePromise;
  }

  async sendClaimedMessage(message, options = {}) {
    const client = options.client || this.getWhatsappClient();
    const laravelMessageClient = options.laravelMessageClient || await this.ensureLaravelClient();

    if (!client) {
      const error = new Error('WhatsApp client is not available for this session.');
      error.code = 'WHATSAPP_CLIENT_MISSING';
      this.lastError = sanitizeError(error);
      return { success: false, failed: false, error: error.message };
    }

    if (this.whatsappTestRecipient) {
      const expectedRecipient = normalizeRecipient(this.whatsappTestRecipient);
      const actualRecipient = normalizeRecipient(message?.recipient);

      if (!actualRecipient || actualRecipient !== expectedRecipient) {
        this.logger.warn('Queued message recipient is blocked by WHATSAPP_TEST_RECIPIENT.', {
          accountId: this.accountId,
          message_id: message?.id,
          recipient: message?.recipient,
          allowed_recipient: this.whatsappTestRecipient,
        });

        return { success: false, skipped: true, reason: 'recipient_mismatch', messageId: message?.id };
      }
    }

    const result = await sendQueuedMessage(client, message, {
      logger: this.logger,
      laravelMessageClient,
    });

    this.processedCount += 1;

    if (result?.success) {
      this.sentCount += 1;
      this.lastError = null;
    } else if (result?.failed) {
      this.failedCount += 1;
      this.lastError = sanitizeError(new Error(result.error || 'Session message send failed.'));
    } else if (result?.recoverable) {
      this.lastError = sanitizeError(new Error(result.error || 'Recoverable session send error.'));
    }

    return result;
  }

  async ensureLaravelClient() {
    if (this.laravelMessageClient) {
      return this.laravelMessageClient;
    }

    const apiToken = await this.resolveSessionApiToken();

    if (!apiToken) {
      const error = new Error('Session API token is not available.');
      error.code = 'SESSION_API_TOKEN_MISSING';
      throw error;
    }

    this.cachedApiToken = apiToken;
    this.laravelMessageClient = this.createLaravelClient({ apiToken });

    return this.laravelMessageClient;
  }

  async resolveSessionApiToken() {
    if (this.cachedApiToken) {
      return this.cachedApiToken;
    }

    if (typeof this.resolveApiToken === 'function') {
      return this.resolveApiToken({
        accountId: this.accountId,
        sessionName: this.sessionName,
      });
    }

    return null;
  }

  getSnapshot() {
    return {
      accountId: this.accountId,
      sessionName: this.sessionName,
      isRunning: this.isRunning,
      isCycleRunning: this.isCycleRunning,
      hasTimer: Boolean(this.timer),
      lastCycleStartedAt: this.lastCycleStartedAt,
      lastCycleFinishedAt: this.lastCycleFinishedAt,
      lastError: this.lastError,
      processedCount: this.processedCount,
      sentCount: this.sentCount,
      failedCount: this.failedCount,
    };
  }
}

module.exports = {
  SessionMessageWorker,
  sanitizeError,
};