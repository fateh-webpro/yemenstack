const { config } = require('./config');

const ensureToken = (apiToken, errorCode = 'ENGINE_API_TOKEN_MISSING', message = 'ENGINE_API_TOKEN is not configured.') => {
  if (!apiToken) {
    const error = new Error(message);
    error.code = errorCode;
    throw error;
  }
};

const ensureInternalToken = (token = config.whatsappEngineInternalToken) => {
  ensureToken(
    token,
    'WHATSAPP_ENGINE_INTERNAL_TOKEN_MISSING',
    'WHATSAPP_ENGINE_INTERNAL_TOKEN is not configured.',
  );
};

const ensureValidAccountId = (accountId) => {
  const normalizedAccountId = String(accountId ?? '').trim();

  if (!/^\d+$/.test(normalizedAccountId) || Number.parseInt(normalizedAccountId, 10) <= 0) {
    const error = new Error('A valid accountId is required.');
    error.code = 'ENGINE_SESSION_ACCOUNT_ID_INVALID';
    throw error;
  }

  return normalizedAccountId;
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

const buildUrl = (path) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  return new URL(path, baseUrl);
};

const appendSearchParams = (url, params = {}) => {
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') {
      continue;
    }

    url.searchParams.set(key, String(value));
  }

  return url;
};

const buildPendingMessagesUrl = (limit = config.fetchLimit, options = {}) => {
  const url = buildUrl(config.pendingMessagesPath);
  return appendSearchParams(url, { limit, ...options });
};

const buildQueuedMessagesUrl = (limit = config.fetchLimit, options = {}) => {
  const url = buildUrl(config.queuedMessagesPath);
  return appendSearchParams(url, { limit, ...options });
};

const buildAccountStatusUrl = () => buildUrl(config.accountStatusPath);
const buildClaimMessageUrl = (messageId) => buildUrl(config.claimMessagePathTemplate.replace(':id', String(messageId)));
const buildDeferMessageUrl = (messageId) => buildUrl(`/api/v1/whatsapp/engine/messages/${encodeURIComponent(String(messageId))}/defer`);
const buildMarkSentUrl = (messageId) => buildUrl(config.markSentPathTemplate.replace(':id', String(messageId)));
const buildMarkFailedUrl = (messageId) => buildUrl(config.markFailedPathTemplate.replace(':id', String(messageId)));

const buildEngineSessionsUrl = (filters = {}) => {
  const url = buildUrl('/api/v1/whatsapp/engine/sessions');
  return appendSearchParams(url, filters);
};

const buildEngineSessionUrl = (accountId) => buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}`);
const buildEngineSessionStartUrl = (accountId) => buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}/start`);
const buildEngineSessionStopUrl = (accountId) => buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}/stop`);
const buildEngineSessionStatusUrl = (accountId) => buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}/status`);
const buildEngineSessionQrUrl = (accountId) => buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}/qr`);

const buildEngineSessionPendingMessagesUrl = (accountId, limit = config.fetchLimit, options = {}) => {
  const url = buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}/messages/pending`);
  return appendSearchParams(url, { limit, ...options });
};

const buildEngineSessionQueuedMessagesUrl = (accountId, limit = config.fetchLimit, options = {}) => {
  const url = buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}/messages/queued`);
  return appendSearchParams(url, { limit, ...options });
};

const buildEngineSessionClaimMessageUrl = (accountId, messageId) => buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}/messages/${encodeURIComponent(String(messageId))}/claim`);
const buildEngineSessionDeferMessageUrl = (accountId, messageId) => buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}/messages/${encodeURIComponent(String(messageId))}/defer`);
const buildEngineSessionMarkSentUrl = (accountId, messageId) => buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}/messages/${encodeURIComponent(String(messageId))}/mark-sent`);
const buildEngineSessionMarkFailedUrl = (accountId, messageId) => buildUrl(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(ensureValidAccountId(accountId))}/messages/${encodeURIComponent(String(messageId))}/mark-failed`);

const requestLaravelJson = async (url, options = {}) => {
  const { method = 'GET', token, body } = options;
  const headers = {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(url, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const payload = await parseJson(response);

  if (!response.ok) {
    throwHttpError(response, payload);
  }

  return payload;
};

const createLaravelClient = ({ apiToken } = {}) => {
  const ensureMessageToken = () => {
    ensureToken(apiToken, 'SESSION_API_TOKEN_MISSING', 'Session API token is not configured.');
  };

  return {
    fetchPendingMessages: async (limit = config.fetchLimit, options = {}) => {
      ensureMessageToken();
      return requestLaravelJson(buildPendingMessagesUrl(limit, options), { method: 'GET', token: apiToken });
    },
    fetchQueuedMessages: async (limit = config.fetchLimit, options = {}) => {
      ensureMessageToken();
      return requestLaravelJson(buildQueuedMessagesUrl(limit, options), { method: 'GET', token: apiToken });
    },
    claimMessage: async (messageId, body = {}) => {
      ensureMessageToken();
      return requestLaravelJson(buildClaimMessageUrl(messageId), { method: 'POST', token: apiToken, body });
    },
    deferMessage: async (messageId, extra = {}) => {
      ensureMessageToken();
      return requestLaravelJson(buildDeferMessageUrl(messageId), { method: 'POST', token: apiToken, body: extra });
    },
    markMessageSent: async (messageId, extra = {}) => {
      ensureMessageToken();
      const body = Object.keys(extra).length > 0 ? extra : { mode: 'simulation' };
      return requestLaravelJson(buildMarkSentUrl(messageId), { method: 'POST', token: apiToken, body });
    },
    markMessageFailed: async (messageId, extra = {}) => {
      ensureMessageToken();
      return requestLaravelJson(buildMarkFailedUrl(messageId), { method: 'POST', token: apiToken, body: extra });
    },
    updateWhatsappAccountStatus: async (status, extra = {}) => {
      ensureMessageToken();
      return requestLaravelJson(buildAccountStatusUrl(), { method: 'POST', token: apiToken, body: { status, ...extra } });
    },
  };
};

const createEngineSessionMessageClient = ({ internalToken, accountId } = {}) => {
  const normalizedAccountId = ensureValidAccountId(accountId);

  const ensureCentralMessageClient = () => {
    ensureInternalToken(internalToken);
  };

  return {
    fetchPendingMessages: async (limit = config.fetchLimit, options = {}) => {
      ensureCentralMessageClient();
      return requestLaravelJson(buildEngineSessionPendingMessagesUrl(normalizedAccountId, limit, options), { method: 'GET', token: internalToken });
    },
    fetchQueuedMessages: async (limit = config.fetchLimit, options = {}) => {
      ensureCentralMessageClient();
      return requestLaravelJson(buildEngineSessionQueuedMessagesUrl(normalizedAccountId, limit, options), { method: 'GET', token: internalToken });
    },
    claimMessage: async (messageId, body = {}) => {
      ensureCentralMessageClient();
      return requestLaravelJson(buildEngineSessionClaimMessageUrl(normalizedAccountId, messageId), { method: 'POST', token: internalToken, body });
    },
    deferMessage: async (messageId, extra = {}) => {
      ensureCentralMessageClient();
      return requestLaravelJson(buildEngineSessionDeferMessageUrl(normalizedAccountId, messageId), { method: 'POST', token: internalToken, body: extra });
    },
    markMessageSent: async (messageId, extra = {}) => {
      ensureCentralMessageClient();
      const body = Object.keys(extra).length > 0 ? extra : { mode: 'simulation' };
      return requestLaravelJson(buildEngineSessionMarkSentUrl(normalizedAccountId, messageId), { method: 'POST', token: internalToken, body });
    },
    markMessageFailed: async (messageId, extra = {}) => {
      ensureCentralMessageClient();
      return requestLaravelJson(buildEngineSessionMarkFailedUrl(normalizedAccountId, messageId), { method: 'POST', token: internalToken, body: extra });
    },
    updateSessionStatus: async (status, extra = {}) => {
      ensureCentralMessageClient();
      return requestLaravelJson(buildEngineSessionStatusUrl(normalizedAccountId), { method: 'POST', token: internalToken, body: { status, ...extra } });
    },
    storeSessionQr: async (qr, extra = {}) => {
      ensureCentralMessageClient();
      return requestLaravelJson(buildEngineSessionQrUrl(normalizedAccountId), { method: 'POST', token: internalToken, body: { qr, ...extra } });
    },
  };
};

const fetchPendingMessages = async (limit = config.fetchLimit, options = {}) => createLaravelClient({ apiToken: config.engineApiToken }).fetchPendingMessages(limit, options);
const fetchQueuedMessages = async (limit = config.fetchLimit, options = {}) => createLaravelClient({ apiToken: config.engineApiToken }).fetchQueuedMessages(limit, options);
const claimMessage = async (messageId, body = {}) => createLaravelClient({ apiToken: config.engineApiToken }).claimMessage(messageId, body);
const deferMessage = async (messageId, extra = {}) => createLaravelClient({ apiToken: config.engineApiToken }).deferMessage(messageId, extra);
const markMessageSent = async (messageId, extra = {}) => createLaravelClient({ apiToken: config.engineApiToken }).markMessageSent(messageId, extra);
const markMessageFailed = async (messageId, extra = {}) => createLaravelClient({ apiToken: config.engineApiToken }).markMessageFailed(messageId, extra);
const updateWhatsappAccountStatus = async (status, extra = {}) => createLaravelClient({ apiToken: config.engineApiToken }).updateWhatsappAccountStatus(status, extra);

const getEngineSessions = async (filters = {}) => {
  ensureInternalToken();
  return requestLaravelJson(buildEngineSessionsUrl(filters), { method: 'GET', token: config.whatsappEngineInternalToken });
};

const getEngineSession = async (accountId) => {
  ensureInternalToken();
  return requestLaravelJson(buildEngineSessionUrl(accountId), { method: 'GET', token: config.whatsappEngineInternalToken });
};

const requestEngineSessionStart = async (accountId) => {
  ensureInternalToken();
  return requestLaravelJson(buildEngineSessionStartUrl(accountId), { method: 'POST', token: config.whatsappEngineInternalToken, body: {} });
};

const requestEngineSessionStop = async (accountId) => {
  ensureInternalToken();
  return requestLaravelJson(buildEngineSessionStopUrl(accountId), { method: 'POST', token: config.whatsappEngineInternalToken, body: {} });
};

module.exports = {
  buildPendingMessagesUrl,
  buildQueuedMessagesUrl,
  buildAccountStatusUrl,
  buildClaimMessageUrl,
  buildDeferMessageUrl,
  buildMarkSentUrl,
  buildMarkFailedUrl,
  buildEngineSessionsUrl,
  buildEngineSessionUrl,
  buildEngineSessionStartUrl,
  buildEngineSessionStopUrl,
  buildEngineSessionStatusUrl,
  buildEngineSessionQrUrl,
  buildEngineSessionPendingMessagesUrl,
  buildEngineSessionQueuedMessagesUrl,
  buildEngineSessionClaimMessageUrl,
  buildEngineSessionDeferMessageUrl,
  buildEngineSessionMarkSentUrl,
  buildEngineSessionMarkFailedUrl,
  requestLaravelJson,
  createLaravelClient,
  createEngineSessionMessageClient,
  fetchPendingMessages,
  fetchQueuedMessages,
  claimMessage,
  deferMessage,
  markMessageSent,
  markMessageFailed,
  updateWhatsappAccountStatus,
  getEngineSessions,
  getEngineSession,
  requestEngineSessionStart,
  requestEngineSessionStop,
  ensureValidAccountId,
};