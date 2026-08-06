const toNumber = (value, fallback) => {
  const parsed = Number.parseInt(value, 10);

  return Number.isNaN(parsed) ? fallback : parsed;
};

const clamp = (value, min, max) => {
  return Math.min(Math.max(value, min), max);
};

const toBoolean = (value, fallback) => {
  if (value === undefined) {
    return fallback;
  }

  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
};

const toBooleanStrict = (value, fallback) => {
  if (value === undefined) {
    return fallback;
  }

  const normalized = String(value).toLowerCase();

  if (['1', 'true', 'yes', 'on'].includes(normalized)) {
    return true;
  }

  if (['0', 'false', 'no', 'off'].includes(normalized)) {
    return false;
  }

  return fallback;
};

const maskToken = (token) => {
  if (!token) {
    return '';
  }

  if (token.length <= 8) {
    return '***';
  }

  return `${token.slice(0, 4)}...${token.slice(-4)}`;
};

const parseMultiSessionAccountIds = (value) => {
  if (value === undefined || value === null || String(value).trim() === '') {
    return [];
  }

  const uniqueIds = new Set();

  for (const rawPart of String(value).split(',')) {
    const part = rawPart.trim();

    if (!/^\d+$/.test(part)) {
      const error = new Error('WHATSAPP_MULTI_SESSION_ACCOUNT_IDS must contain only positive numeric account ids.');
      error.code = 'WHATSAPP_MULTI_SESSION_ACCOUNT_IDS_INVALID';
      throw error;
    }

    const parsed = Number.parseInt(part, 10);

    if (!Number.isInteger(parsed) || parsed <= 0) {
      const error = new Error('WHATSAPP_MULTI_SESSION_ACCOUNT_IDS must contain only positive numeric account ids.');
      error.code = 'WHATSAPP_MULTI_SESSION_ACCOUNT_IDS_INVALID';
      throw error;
    }

    uniqueIds.add(parsed);
  }

  return Array.from(uniqueIds.values());
};

const normalizeSendDelayRange = () => {
  const defaultMin = 15000;
  const defaultMax = 30000;
  const parsedMin = toNumber(process.env.WHATSAPP_SEND_DELAY_MIN_MS, defaultMin);
  const parsedMax = toNumber(process.env.WHATSAPP_SEND_DELAY_MAX_MS, defaultMax);
  const min = Math.max(parsedMin, defaultMin);
  const max = Math.max(parsedMax, defaultMin);

  if (max < min) {
    return {
      min: defaultMin,
      max: defaultMax,
    };
  }

  return { min, max };
};

const normalizeTypingDelayRange = () => {
  const defaultMin = 3000;
  const defaultMax = 7000;
  const parsedMin = toNumber(process.env.WHATSAPP_TYPING_DELAY_MIN_MS, defaultMin);
  const parsedMax = toNumber(process.env.WHATSAPP_TYPING_DELAY_MAX_MS, defaultMax);

  if (!Number.isInteger(parsedMin) || !Number.isInteger(parsedMax) || parsedMin < 0 || parsedMax < 0 || parsedMin > parsedMax) {
    return {
      min: defaultMin,
      max: defaultMax,
    };
  }

  return {
    min: parsedMin,
    max: parsedMax,
  };
};

const sendDelayRange = normalizeSendDelayRange();
const typingDelayRange = normalizeTypingDelayRange();

const config = {
  nodeEnv: process.env.NODE_ENV || 'development',
  engineName: process.env.ENGINE_NAME || 'yemenstack-whatsapp-engine',
  pollIntervalMs: toNumber(process.env.ENGINE_POLL_INTERVAL_MS, 5000),
  laravelBaseUrl: process.env.LARAVEL_BASE_URL || 'http://127.0.0.1:8000',
  engineApiToken: process.env.ENGINE_API_TOKEN || '',
  whatsappEngineInternalToken: process.env.WHATSAPP_ENGINE_INTERNAL_TOKEN || '',
  multiSessionEnabled: toBoolean(process.env.WHATSAPP_MULTI_SESSION_ENABLED, false),
  multiSessionAccountIdsRaw: process.env.WHATSAPP_MULTI_SESSION_ACCOUNT_IDS || '',
  pendingMessagesPath: process.env.ENGINE_PENDING_MESSAGES_PATH || '/api/v1/whatsapp/engine/messages/pending',
  queuedMessagesPath: process.env.ENGINE_QUEUED_MESSAGES_PATH || '/api/v1/whatsapp/engine/messages/queued',
  accountStatusPath: process.env.ENGINE_ACCOUNT_STATUS_PATH || '/api/v1/whatsapp/engine/account/status',
  claimMessagePathTemplate: process.env.ENGINE_CLAIM_MESSAGE_PATH_TEMPLATE || '/api/v1/whatsapp/engine/messages/:id/claim',
  markSentPathTemplate: process.env.ENGINE_MARK_SENT_PATH_TEMPLATE || '/api/v1/whatsapp/engine/messages/:id/mark-sent',
  markFailedPathTemplate: process.env.ENGINE_MARK_FAILED_PATH_TEMPLATE || '/api/v1/whatsapp/engine/messages/:id/mark-failed',
  whatsappSessionId: process.env.WHATSAPP_SESSION_ID || 'default',
  whatsappChromePath: process.env.WHATSAPP_CHROME_PATH || '',
  whatsappHeadless: toBoolean(process.env.WHATSAPP_HEADLESS, true),
  whatsappQrTerminalSmall: toBoolean(process.env.WHATSAPP_QR_TERMINAL_SMALL, true),
  enableRealWhatsappSend: toBoolean(process.env.ENABLE_REAL_WHATSAPP_SEND, false),
  whatsappTestRecipient: process.env.WHATSAPP_TEST_RECIPIENT || '',
  fetchLimit: clamp(toNumber(process.env.ENGINE_FETCH_LIMIT, 10), 1, 50),
  whatsappSendLimit: clamp(toNumber(process.env.WHATSAPP_SEND_LIMIT, 1), 1, 1),
  whatsappSendDelayMinMs: sendDelayRange.min,
  whatsappSendDelayMaxMs: sendDelayRange.max,
  whatsappTypingIndicatorEnabled: toBooleanStrict(process.env.WHATSAPP_TYPING_INDICATOR_ENABLED, true),
  whatsappTypingDelayMinMs: typingDelayRange.min,
  whatsappTypingDelayMaxMs: typingDelayRange.max,
  whatsappRestartDelayMs: clamp(toNumber(process.env.WHATSAPP_RESTART_DELAY_MS, 3000), 1000, 60000),
  whatsappRestartTimeoutMs: clamp(toNumber(process.env.WHATSAPP_RESTART_TIMEOUT_MS, 60000), 10000, 180000),
  whatsappMaxRestartAttempts: clamp(toNumber(process.env.WHATSAPP_MAX_RESTART_ATTEMPTS, 3), 1, 10),
  sessionRecoveryInitialDelayMs: clamp(toNumber(process.env.SESSION_RECOVERY_INITIAL_DELAY_MS, 5000), 1000, 60000),
  sessionRecoveryMaxDelayMs: clamp(toNumber(process.env.SESSION_RECOVERY_MAX_DELAY_MS, 60000), 1000, 300000),
  sessionRecoveryCooldownMs: clamp(toNumber(process.env.SESSION_RECOVERY_COOLDOWN_MS, 120000), 10000, 900000),
  sessionRecoveryMaxAttempts: clamp(toNumber(process.env.SESSION_RECOVERY_MAX_ATTEMPTS, 4), 1, 10),
  sessionReadyTimeoutMs: clamp(toNumber(process.env.SESSION_READY_TIMEOUT_MS, 60000), 10000, 180000),
  whatsappHeartbeatIntervalMs: clamp(toNumber(process.env.WHATSAPP_HEARTBEAT_INTERVAL_MS, 30000), 10000, 300000),
};

const getPublicConfig = () => ({
  nodeEnv: config.nodeEnv,
  engineName: config.engineName,
  pollIntervalMs: config.pollIntervalMs,
  laravelBaseUrl: config.laravelBaseUrl,
  pendingMessagesPath: config.pendingMessagesPath,
  queuedMessagesPath: config.queuedMessagesPath,
  accountStatusPath: config.accountStatusPath,
  claimMessagePathTemplate: config.claimMessagePathTemplate,
  markSentPathTemplate: config.markSentPathTemplate,
  markFailedPathTemplate: config.markFailedPathTemplate,
  whatsappSessionId: config.whatsappSessionId,
  whatsappChromePath: config.whatsappChromePath,
  whatsappHeadless: config.whatsappHeadless,
  whatsappQrTerminalSmall: config.whatsappQrTerminalSmall,
  enableRealWhatsappSend: config.enableRealWhatsappSend,
  whatsappTestRecipient: config.whatsappTestRecipient,
  fetchLimit: config.fetchLimit,
  whatsappSendLimit: config.whatsappSendLimit,
  whatsappSendDelayMinMs: config.whatsappSendDelayMinMs,
  whatsappSendDelayMaxMs: config.whatsappSendDelayMaxMs,
  whatsappTypingIndicatorEnabled: config.whatsappTypingIndicatorEnabled,
  whatsappTypingDelayMinMs: config.whatsappTypingDelayMinMs,
  whatsappTypingDelayMaxMs: config.whatsappTypingDelayMaxMs,
  whatsappRestartDelayMs: config.whatsappRestartDelayMs,
  whatsappRestartTimeoutMs: config.whatsappRestartTimeoutMs,
  whatsappMaxRestartAttempts: config.whatsappMaxRestartAttempts,
  sessionRecoveryInitialDelayMs: config.sessionRecoveryInitialDelayMs,
  sessionRecoveryMaxDelayMs: config.sessionRecoveryMaxDelayMs,
  sessionRecoveryCooldownMs: config.sessionRecoveryCooldownMs,
  sessionRecoveryMaxAttempts: config.sessionRecoveryMaxAttempts,
  sessionReadyTimeoutMs: config.sessionReadyTimeoutMs,
  whatsappHeartbeatIntervalMs: config.whatsappHeartbeatIntervalMs,
  multiSessionEnabled: config.multiSessionEnabled,
  multiSessionAccountIdsRaw: config.multiSessionAccountIdsRaw,
  multiSessionAccountIds: parseMultiSessionAccountIds(config.multiSessionAccountIdsRaw),
  engineApiTokenMasked: maskToken(config.engineApiToken),
  whatsappEngineInternalTokenMasked: maskToken(config.whatsappEngineInternalToken),
});

module.exports = {
  config,
  getPublicConfig,
  maskToken,
  parseMultiSessionAccountIds,
};