const path = require('path');
const qrcode = require('qrcode-terminal');
const { Client, LocalAuth } = require('whatsapp-web.js');
const { config, getPublicConfig } = require('./config');
const logger = require('./logger');
const { pollPendingMessages } = require('./pendingMessages');
const { fetchQueuedMessages, updateWhatsappAccountStatus } = require('./laravelClient');
const { normalizeRecipient, isRecoverableWhatsappError, sendQueuedMessage } = require('./realMessageSender');

const CLIENT_RESTART_WINDOW_MS = 5 * 60 * 1000;

let client = null;
let clientGeneration = 0;
let clientStartupState = null;
let isReady = false;
let isShuttingDown = false;
let isRestartingClient = false;
let currentRestartPromise = null;
let pollTimer = null;
let isCycleRunning = false;
let restartAttemptTimestamps = [];

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

const updateStatus = async (status, extra = {}) => {
  try {
    await updateWhatsappAccountStatus(status, extra);
  } catch (error) {
    logger.error('Failed to update WhatsApp account status.', {
      service: 'whatsapp-gateway',
      status,
      code: error.code || 'unknown_error',
      http_status: error.status || null,
      message: error.message,
    });
  }
};

const resolveDisconnectStatus = (reason) => {
  const normalizedReason = String(reason || '').toLowerCase();

  if (normalizedReason.includes('logout')) {
    return 'logged_out';
  }

  return 'disconnected';
};

const shouldAllowRecipient = (message) => {
  if (!config.whatsappTestRecipient) {
    return true;
  }

  return normalizeRecipient(message?.recipient) === normalizeRecipient(config.whatsappTestRecipient);
};

const isCurrentClient = (targetClient, generation) => {
  return client === targetClient && clientGeneration === generation;
};

const createClientStartupState = (generation) => {
  let settled = false;
  let resolvePromise;
  let rejectPromise;

  const promise = new Promise((resolve, reject) => {
    resolvePromise = resolve;
    rejectPromise = reject;
  });

  const timeout = setTimeout(() => {
    if (settled) {
      return;
    }

    settled = true;

    if (clientStartupState?.generation === generation) {
      clientStartupState = null;
    }

    rejectPromise(new Error(`WhatsApp client ready timeout after ${config.whatsappRestartTimeoutMs}ms.`));
  }, config.whatsappRestartTimeoutMs);

  return {
    generation,
    promise,
    resolve: (value) => {
      if (settled) {
        return;
      }

      settled = true;
      clearTimeout(timeout);

      if (clientStartupState?.generation === generation) {
        clientStartupState = null;
      }

      resolvePromise(value);
    },
    reject: (error) => {
      if (settled) {
        return;
      }

      settled = true;
      clearTimeout(timeout);

      if (clientStartupState?.generation === generation) {
        clientStartupState = null;
      }

      rejectPromise(error instanceof Error ? error : new Error(String(error || 'Unknown startup error')));
    },
  };
};

const safeDestroyClient = async (targetClient, reason) => {
  if (!targetClient) {
    return;
  }

  try {
    await targetClient.destroy();
  } catch (error) {
    logger.warn('Failed to destroy WhatsApp client cleanly.', {
      service: 'whatsapp-gateway',
      reason,
      message: error.message,
    });
  }
};

const trimRestartAttemptsWindow = () => {
  const now = Date.now();
  restartAttemptTimestamps = restartAttemptTimestamps.filter(
    (timestamp) => (now - timestamp) < CLIENT_RESTART_WINDOW_MS,
  );

  return now;
};

const createWhatsappClient = () => {
  return new Client({
    authStrategy: new LocalAuth({
      clientId: config.whatsappSessionId,
      dataPath: path.join(__dirname, '..', '.wwebjs_auth'),
    }),
    puppeteer: buildPuppeteerOptions(),
  });
};

const restartWhatsappClient = async (reason, sourceError = null) => {
  if (isShuttingDown) {
    return false;
  }

  if (currentRestartPromise) {
    logger.warn('WhatsApp client restart is already in progress; joining existing request.', {
      service: 'whatsapp-gateway',
      reason,
    });

    return currentRestartPromise;
  }

  const now = trimRestartAttemptsWindow();

  if (restartAttemptTimestamps.length >= config.whatsappMaxRestartAttempts) {
    logger.error('WhatsApp client restart limit exceeded; client will remain stopped until manual intervention.', {
      service: 'whatsapp-gateway',
      reason,
      max_attempts: config.whatsappMaxRestartAttempts,
      window_ms: CLIENT_RESTART_WINDOW_MS,
      message: sourceError instanceof Error ? sourceError.message : null,
    });

    isReady = false;
    stopPolling();

    await updateStatus('error', {
      note: `Restart limit exceeded: ${reason}`,
    });

    return false;
  }

  restartAttemptTimestamps.push(now);

  currentRestartPromise = (async () => {
    isRestartingClient = true;
    isReady = false;
    stopPolling();

    const oldClient = client;
    client = null;

    logger.warn('Restarting WhatsApp client after recoverable connection issue.', {
      service: 'whatsapp-gateway',
      reason,
      delay_ms: config.whatsappRestartDelayMs,
      timeout_ms: config.whatsappRestartTimeoutMs,
      max_attempts: config.whatsappMaxRestartAttempts,
      message: sourceError instanceof Error ? sourceError.message : null,
    });

    await updateStatus('connecting', {
      note: `Client restart requested: ${reason}`,
    });

    await safeDestroyClient(oldClient, 'restart');
    await delay(config.whatsappRestartDelayMs);

    try {
      await initializeWhatsappClient(`restart:${reason}`);

      logger.info('WhatsApp client restarted successfully.', {
        service: 'whatsapp-gateway',
        reason,
      });

      return true;
    } catch (error) {
      logger.error('WhatsApp client restart failed.', {
        service: 'whatsapp-gateway',
        reason,
        message: error.message,
      });

      await updateStatus('error', {
        note: `Client restart failed: ${error.message}`,
      });

      return false;
    } finally {
      isRestartingClient = false;
      currentRestartPromise = null;
    }
  })();

  return currentRestartPromise;
};

const attachWhatsappClientEvents = (targetClient, generation) => {
  targetClient.on('qr', async (qr) => {
    if (!isCurrentClient(targetClient, generation)) {
      return;
    }

    logger.info('WhatsApp QR received', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
      generation,
    });

    qrcode.generate(qr, { small: config.whatsappQrTerminalSmall });

    await updateStatus('qr_required', {
      qr_expires_at: new Date(Date.now() + 60 * 1000).toISOString(),
    });
  });

  targetClient.on('authenticated', () => {
    if (!isCurrentClient(targetClient, generation)) {
      return;
    }

    logger.info('WhatsApp client authenticated', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
      generation,
    });
  });

  targetClient.on('ready', async () => {
    if (!isCurrentClient(targetClient, generation)) {
      return;
    }

    isReady = true;

    if (clientStartupState?.generation === generation) {
      clientStartupState.resolve(true);
    }

    logger.info('WhatsApp client is ready', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
      generation,
    });

    await updateStatus('connected', {
      last_seen_at: new Date().toISOString(),
    });

    startPolling();
  });

  targetClient.on('change_state', (state) => {
    if (!isCurrentClient(targetClient, generation)) {
      return;
    }

    logger.info('WhatsApp client state changed.', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
      generation,
      state,
    });
  });

  targetClient.on('auth_failure', async (message) => {
    if (!isCurrentClient(targetClient, generation)) {
      return;
    }

    isReady = false;
    stopPolling();

    const error = new Error(typeof message === 'string' ? message : 'Authentication failure.');

    if (clientStartupState?.generation === generation) {
      clientStartupState.reject(error);
    }

    logger.error('WhatsApp authentication failure.', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
      generation,
      message,
    });

    await updateStatus('error', {
      note: typeof message === 'string' ? message : 'Authentication failure.',
    });
  });

  targetClient.on('disconnected', async (reason) => {
    if (!isCurrentClient(targetClient, generation)) {
      return;
    }

    isReady = false;
    stopPolling();

    const status = resolveDisconnectStatus(reason);
    const disconnectError = new Error(`WhatsApp client disconnected: ${String(reason || 'unknown')}`);

    if (clientStartupState?.generation === generation) {
      clientStartupState.reject(disconnectError);
    }

    logger.warn('WhatsApp client disconnected.', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
      generation,
      reason,
      status,
    });

    await updateStatus(status, {
      note: `Disconnected reason: ${String(reason || 'unknown')}`,
    });

    if (!isShuttingDown && status !== 'logged_out') {
      void restartWhatsappClient(`client_disconnected:${String(reason || 'unknown')}`, disconnectError);
    }
  });

  targetClient.on('error', async (error) => {
    if (!isCurrentClient(targetClient, generation)) {
      return;
    }

    const recoverable = isRecoverableWhatsappError(error);
    const errorMessage = error instanceof Error ? error.message : String(error || 'Unknown WhatsApp client error');

    logger[recoverable ? 'warn' : 'error']('WhatsApp client emitted an error event.', {
      service: 'whatsapp-gateway',
      session_id: config.whatsappSessionId,
      generation,
      recoverable,
      message: errorMessage,
    });

    if (recoverable && !isShuttingDown) {
      void restartWhatsappClient(`client_error_event:${errorMessage}`, error);
      return;
    }

    await updateStatus('error', {
      note: errorMessage,
    });
  });
};

const initializeWhatsappClient = async (reason = 'startup') => {
  const nextClient = createWhatsappClient();
  const generation = ++clientGeneration;

  client = nextClient;
  clientStartupState = createClientStartupState(generation);

  attachWhatsappClientEvents(nextClient, generation);

  logger.info('Initializing WhatsApp client.', {
    service: 'whatsapp-gateway',
    reason,
    generation,
    session_id: config.whatsappSessionId,
  });

  try {
    await nextClient.initialize();
    await clientStartupState.promise;

    return nextClient;
  } catch (error) {
    if (isCurrentClient(nextClient, generation)) {
      client = null;
    }

    await safeDestroyClient(nextClient, 'initialization_failure');

    throw error;
  }
};

const processQueuedMessages = async () => {
  if (!client || !isReady || isRestartingClient || isShuttingDown) {
    return { success: false, skipped: true, reason: 'client_not_ready' };
  }

  const payload = await fetchQueuedMessages(config.whatsappSendLimit);
  const messages = Array.isArray(payload?.data) ? payload.data : [];

  logger.info('Queued messages fetched for engine cycle.', {
    service: 'whatsapp-gateway',
    count: messages.length,
    limit: config.whatsappSendLimit,
  });

  if (!config.enableRealWhatsappSend) {
    logger.info('Real WhatsApp send is disabled; queued messages will not be sent.', {
      service: 'whatsapp-gateway',
      enabled: false,
      limit: config.whatsappSendLimit,
    });

    return payload;
  }

  for (const message of messages.slice(0, config.whatsappSendLimit)) {
    if (isShuttingDown || isRestartingClient || !isReady || !client) {
      logger.warn('Stopping queued messages processing because the WhatsApp client is not available.', {
        service: 'whatsapp-gateway',
      });

      break;
    }

    if (!shouldAllowRecipient(message)) {
      logger.warn('Queued message recipient is blocked by WHATSAPP_TEST_RECIPIENT.', {
        service: 'whatsapp-gateway',
        message_id: message.id,
        recipient: message.recipient,
        allowed_recipient: config.whatsappTestRecipient,
      });

      continue;
    }

    const sendResult = await sendQueuedMessage(client, message);

    if (sendResult?.recoverable) {
      logger.warn('Recoverable WhatsApp send error detected; restarting client.', {
        service: 'whatsapp-gateway',
        message_id: sendResult.messageId ?? message.id,
        stage: sendResult.stage ?? 'unknown',
        safe_to_retry: sendResult.safeToRetry ?? false,
        failed: sendResult.failed ?? false,
        message: sendResult.error ?? 'Unknown recoverable error',
      });

      await restartWhatsappClient(`recoverable_send_error:${sendResult.stage ?? 'unknown'}`);
      break;
    }
  }

  return payload;
};

const runPollCycle = async () => {
  if (!isReady || isShuttingDown || isRestartingClient) {
    return;
  }

  if (isCycleRunning) {
    logger.warn('Previous engine cycle is still running; skipping this interval.', {
      service: 'whatsapp-gateway',
      interval_ms: config.pollIntervalMs,
    });

    return;
  }

  isCycleRunning = true;

  logger.info('Engine heartbeat', {
    service: 'whatsapp-gateway',
    status: 'running',
    ready: isReady,
    restarting_client: isRestartingClient,
    real_send_enabled: config.enableRealWhatsappSend,
    send_limit: config.whatsappSendLimit,
  });

  try {
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

    if (isShuttingDown || isRestartingClient || !isReady) {
      return;
    }

    try {
      await processQueuedMessages();
    } catch (error) {
      logger.error('Queued messages process failed.', {
        service: 'whatsapp-gateway',
        code: error.code || 'unknown_error',
        status: error.status || null,
        message: error.message,
      });
    }
  } finally {
    isCycleRunning = false;
  }
};

const startPolling = () => {
  if (pollTimer || isShuttingDown) {
    return;
  }

  logger.info('Starting WhatsApp engine poll loop after client ready.', {
    service: 'whatsapp-gateway',
    poll_interval_ms: config.pollIntervalMs,
    real_send_enabled: config.enableRealWhatsappSend,
    send_limit: config.whatsappSendLimit,
    test_recipient: config.whatsappTestRecipient || null,
  });

  void runPollCycle();

  pollTimer = setInterval(() => {
    void runPollCycle();
  }, config.pollIntervalMs);
};

const stopPolling = () => {
  if (!pollTimer) {
    return;
  }

  clearInterval(pollTimer);
  pollTimer = null;
};

const shutdown = async (signal) => {
  if (isShuttingDown) {
    return;
  }

  isShuttingDown = true;
  isReady = false;
  stopPolling();

  logger.warn('Stopping Yemen Stack WhatsApp engine.', {
    service: 'whatsapp-gateway',
    signal,
  });

  await updateStatus('disconnected', {
    note: `Engine stopped by ${signal}.`,
  });

  const currentClient = client;
  client = null;

  await safeDestroyClient(currentClient, 'shutdown');

  process.exit(0);
};

const startEngine = async () => {
  if (!config.engineApiToken) {
    logger.warn('ENGINE_API_TOKEN is not configured; Yemen Stack WhatsApp engine will not start.', {
      service: 'whatsapp-gateway',
      status: 'skipped',
    });

    return;
  }

  logger.info('Starting Yemen Stack WhatsApp engine.', {
    platform: 'Yemen Stack',
    service: 'whatsapp-gateway',
    mode: 'background-engine',
  });

  logger.info('Engine configuration loaded.', getPublicConfig());

  await updateStatus('connecting');

  process.on('SIGINT', () => {
    void shutdown('SIGINT');
  });

  process.on('SIGTERM', () => {
    void shutdown('SIGTERM');
  });

  await initializeWhatsappClient('startup');
};

if (require.main === module) {
  startEngine().catch(async (error) => {
    logger.error('WhatsApp engine failed to start.', {
      service: 'whatsapp-gateway',
      message: error.message,
    });

    await updateStatus('error', {
      note: error.message,
    });

    process.exitCode = 1;
  });
}

module.exports = {
  startEngine,
  restartWhatsappClient,
};