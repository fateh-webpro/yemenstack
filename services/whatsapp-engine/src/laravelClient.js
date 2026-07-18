const { config } = require('./config');

const ensureToken = () => {
  if (!config.engineApiToken) {
    const error = new Error('ENGINE_API_TOKEN is not configured.');
    error.code = 'ENGINE_API_TOKEN_MISSING';
    throw error;
  }
};

const parseJson = async (response) => {
  try {
    return await response.json();
  } catch (error) {
    return null;
  }
};

const throwHttpError = (response, payload) => {
  const error = new Error(payload?.message || `Laravel API request failed with status ${response.status}.`);
  error.code = 'LARAVEL_API_ERROR';
  error.status = response.status;
  error.payload = payload;
  throw error;
};

const buildPendingMessagesUrl = (limit = config.fetchLimit) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  const url = new URL(config.pendingMessagesPath, baseUrl);
  url.searchParams.set('limit', String(limit));
  return url;
};

const buildQueuedMessagesUrl = (limit = config.fetchLimit) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  const url = new URL(config.queuedMessagesPath, baseUrl);
  url.searchParams.set('limit', String(limit));
  return url;
};

const buildAccountStatusUrl = () => {
  const baseUrl = new URL(config.laravelBaseUrl);
  return new URL(config.accountStatusPath, baseUrl);
};

const buildClaimMessageUrl = (messageId) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  const path = config.claimMessagePathTemplate.replace(':id', String(messageId));
  return new URL(path, baseUrl);
};

const buildMarkSentUrl = (messageId) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  const path = config.markSentPathTemplate.replace(':id', String(messageId));
  return new URL(path, baseUrl);
};

const buildMarkFailedUrl = (messageId) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  const path = config.markFailedPathTemplate.replace(':id', String(messageId));
  return new URL(path, baseUrl);
};

const fetchPendingMessages = async (limit = config.fetchLimit) => {
  ensureToken();
  const response = await fetch(buildPendingMessagesUrl(limit), {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${config.engineApiToken}`,
    },
  });
  const payload = await parseJson(response);
  if (!response.ok) {
    throwHttpError(response, payload);
  }
  return payload;
};

const fetchQueuedMessages = async (limit = config.fetchLimit) => {
  ensureToken();
  const response = await fetch(buildQueuedMessagesUrl(limit), {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${config.engineApiToken}`,
    },
  });
  const payload = await parseJson(response);
  if (!response.ok) {
    throwHttpError(response, payload);
  }
  return payload;
};

const claimMessage = async (messageId) => {
  ensureToken();
  const response = await fetch(buildClaimMessageUrl(messageId), {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${config.engineApiToken}`,
    },
    body: JSON.stringify({}),
  });
  const payload = await parseJson(response);
  if (!response.ok) {
    throwHttpError(response, payload);
  }
  return payload;
};

const markMessageSent = async (messageId, extra = {}) => {
  ensureToken();
  const body = Object.keys(extra).length > 0 ? extra : { mode: 'simulation' };
  const response = await fetch(buildMarkSentUrl(messageId), {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${config.engineApiToken}`,
    },
    body: JSON.stringify(body),
  });
  const payload = await parseJson(response);
  if (!response.ok) {
    throwHttpError(response, payload);
  }
  return payload;
};

const markMessageFailed = async (messageId, extra = {}) => {
  ensureToken();
  const response = await fetch(buildMarkFailedUrl(messageId), {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${config.engineApiToken}`,
    },
    body: JSON.stringify(extra),
  });
  const payload = await parseJson(response);
  if (!response.ok) {
    throwHttpError(response, payload);
  }
  return payload;
};

const updateWhatsappAccountStatus = async (status, extra = {}) => {
  ensureToken();
  const response = await fetch(buildAccountStatusUrl(), {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${config.engineApiToken}`,
    },
    body: JSON.stringify({ status, ...extra }),
  });
  const payload = await parseJson(response);
  if (!response.ok) {
    throwHttpError(response, payload);
  }
  return payload;
};

module.exports = {
  buildPendingMessagesUrl,
  buildQueuedMessagesUrl,
  buildAccountStatusUrl,
  buildClaimMessageUrl,
  buildMarkSentUrl,
  buildMarkFailedUrl,
  fetchPendingMessages,
  fetchQueuedMessages,
  claimMessage,
  markMessageSent,
  markMessageFailed,
  updateWhatsappAccountStatus,
};