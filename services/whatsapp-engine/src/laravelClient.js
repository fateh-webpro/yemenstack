const { config } = require('./config');

const buildPendingMessagesUrl = (limit = config.fetchLimit) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  const url = new URL(config.pendingMessagesPath, baseUrl);

  url.searchParams.set('limit', String(limit));

  return url;
};

const fetchPendingMessages = async (limit = config.fetchLimit) => {
  if (!config.engineApiToken) {
    const error = new Error('ENGINE_API_TOKEN is not configured.');
    error.code = 'ENGINE_API_TOKEN_MISSING';
    throw error;
  }

  const url = buildPendingMessagesUrl(limit);
  const response = await fetch(url, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${config.engineApiToken}`,
    },
  });

  let payload = null;

  try {
    payload = await response.json();
  } catch (error) {
    payload = null;
  }

  if (!response.ok) {
    const error = new Error(payload?.message || `Laravel API request failed with status ${response.status}.`);
    error.code = 'LARAVEL_API_ERROR';
    error.status = response.status;
    error.payload = payload;
    throw error;
  }

  return payload;
};

module.exports = {
  buildPendingMessagesUrl,
  fetchPendingMessages,
};