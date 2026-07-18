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

const maskToken = (token) => {
  if (!token) {
    return '';
  }

  if (token.length <= 8) {
    return '***';
  }

  return `${token.slice(0, 4)}...${token.slice(-4)}`;
};

const config = {
  nodeEnv: process.env.NODE_ENV || 'development',
  engineName: process.env.ENGINE_NAME || 'yemenstack-whatsapp-engine',
  pollIntervalMs: toNumber(process.env.ENGINE_POLL_INTERVAL_MS, 5000),
  laravelBaseUrl: process.env.LARAVEL_BASE_URL || 'http://127.0.0.1:8000',
  engineApiToken: process.env.ENGINE_API_TOKEN || '',
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
  engineApiTokenMasked: maskToken(config.engineApiToken),
});

module.exports = {
  config,
  getPublicConfig,
  maskToken,
};