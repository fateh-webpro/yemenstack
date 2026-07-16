const toNumber = (value, fallback) => {
  const parsed = Number.parseInt(value, 10);

  return Number.isNaN(parsed) ? fallback : parsed;
};

const clamp = (value, min, max) => {
  return Math.min(Math.max(value, min), max);
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
  fetchLimit: clamp(toNumber(process.env.ENGINE_FETCH_LIMIT, 10), 1, 50),
};

const getPublicConfig = () => ({
  nodeEnv: config.nodeEnv,
  engineName: config.engineName,
  pollIntervalMs: config.pollIntervalMs,
  laravelBaseUrl: config.laravelBaseUrl,
  pendingMessagesPath: config.pendingMessagesPath,
  fetchLimit: config.fetchLimit,
  engineApiTokenMasked: maskToken(config.engineApiToken),
});

module.exports = {
  config,
  getPublicConfig,
  maskToken,
};