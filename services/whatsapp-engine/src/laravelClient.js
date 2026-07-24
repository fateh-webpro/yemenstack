const { config } = require('./config');

const ensureToken = (apiToken, errorCode = 'ENGINE_API_TOKEN_MISSING', message = 'ENGINE_API_TOKEN is not configured.') => {
  if (!apiToken) {
    const error = new Error(message);
    error.code = errorCode;
    throw error;
  }
};

const ensureInternalToken = () => {
  ensureToken(
    config.whatsappEngineInternalToken,
    'WHATSAPP_ENGINE_INTERNAL_TOKEN_MISSING',
    'WHATSAPP_ENGINE_INTERNAL_TOKEN is not configured.',
  );
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

const buildEngineSessionsUrl = (filters = {}) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  const url = new URL('/api/v1/whatsapp/engine/sessions', baseUrl);

  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === null || value === '') {
      continue;
    }

    url.searchParams.set(key, String(value));
  }

  return url;
};

const buildEngineSessionUrl = (accountId) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  return new URL(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(String(accountId))}`, baseUrl);
};

const buildEngineSessionStartUrl = (accountId) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  return new URL(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(String(accountId))}/start`, baseUrl);
};

const buildEngineSessionStopUrl = (accountId) => {
  const baseUrl = new URL(config.laravelBaseUrl);
  return new URL(`/api/v1/whatsapp/engine/sessions/${encodeURIComponent(String(accountId))}/stop`, baseUrl);
};

const requestLaravelJson = async (url, options = {}) => {
  const {
    method = 'GET',
    token,
    body,
  } = options;

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

const createLaravelClient = (options = {}) => {
  const {
    apiToken,
  } = options;

  const ensureMessageToken = () => {
    ensureToken(apiToken, 'SESSION_API_TOKEN_MISSING', 'Session API token is not configured.');
  };

  return {
    fetchPendingMessages: async (limit = config.fetchLimit) => {
      ensureMessageToken();

      return requestLaravelJson(buildPendingMessagesUrl(limit), {
        method: 'GET',
        token: apiToken,
      });
    },
    fetchQueuedMessages: async (limit = config.fetchLimit) => {
      ensureMessageToken();

      return requestLaravelJson(buildQueuedMessagesUrl(limit), {
        method: 'GET',
        token: apiToken,
      });
    },
    claimMessage: async (messageId) => {
      ensureMessageToken();

      return requestLaravelJson(buildClaimMessageUrl(messageId), {
        method: 'POST',
        token: apiToken,
        body: {},
      });
    },
    markMessageSent: async (messageId, extra = {}) => {
      ensureMessageToken();
      const body = Object.keys(extra).length > 0 ? extra : { mode: 'simulation' };

      return requestLaravelJson(buildMarkSentUrl(messageId), {
        method: 'POST',
        token: apiToken,
        body,
      });
    },
    markMessageFailed: async (messageId, extra = {}) => {
      ensureMessageToken();

      return requestLaravelJson(buildMarkFailedUrl(messageId), {
        method: 'POST',
        token: apiToken,
        body: extra,
      });
    },
    updateWhatsappAccountStatus: async (status, extra = {}) => {
      ensureMessageToken();

      return requestLaravelJson(buildAccountStatusUrl(), {
        method: 'POST',
        token: apiToken,
        body: { status, ...extra },
      });
    },
  };
};

const fetchPendingMessages = async (limit = config.fetchLimit) => {
  return createLaravelClient({ apiToken: config.engineApiToken }).fetchPendingMessages(limit);
};

const fetchQueuedMessages = async (limit = config.fetchLimit) => {
  return createLaravelClient({ apiToken: config.engineApiToken }).fetchQueuedMessages(limit);
};

const claimMessage = async (messageId) => {
  return createLaravelClient({ apiToken: config.engineApiToken }).claimMessage(messageId);
};

const markMessageSent = async (messageId, extra = {}) => {
  return createLaravelClient({ apiToken: config.engineApiToken }).markMessageSent(messageId, extra);
};

const markMessageFailed = async (messageId, extra = {}) => {
  return createLaravelClient({ apiToken: config.engineApiToken }).markMessageFailed(messageId, extra);
};

const updateWhatsappAccountStatus = async (status, extra = {}) => {
  return createLaravelClient({ apiToken: config.engineApiToken }).updateWhatsappAccountStatus(status, extra);
};

const getEngineSessions = async (filters = {}) => {
  ensureInternalToken();

  return requestLaravelJson(buildEngineSessionsUrl(filters), {
    method: 'GET',
    token: config.whatsappEngineInternalToken,
  });
};

const getEngineSession = async (accountId) => {
  ensureInternalToken();

  return requestLaravelJson(buildEngineSessionUrl(accountId), {
    method: 'GET',
    token: config.whatsappEngineInternalToken,
  });
};

const requestEngineSessionStart = async (accountId) => {
  ensureInternalToken();

  return requestLaravelJson(buildEngineSessionStartUrl(accountId), {
    method: 'POST',
    token: config.whatsappEngineInternalToken,
    body: {},
  });
};

const requestEngineSessionStop = async (accountId) => {
  ensureInternalToken();

  return requestLaravelJson(buildEngineSessionStopUrl(accountId), {
    method: 'POST',
    token: config.whatsappEngineInternalToken,
    body: {},
  });
};

module.exports = {
  buildPendingMessagesUrl,
  buildQueuedMessagesUrl,
  buildAccountStatusUrl,
  buildClaimMessageUrl,
  buildMarkSentUrl,
  buildMarkFailedUrl,
  buildEngineSessionsUrl,
  buildEngineSessionUrl,
  buildEngineSessionStartUrl,
  buildEngineSessionStopUrl,
  requestLaravelJson,
  createLaravelClient,
  fetchPendingMessages,
  fetchQueuedMessages,
  claimMessage,
  markMessageSent,
  markMessageFailed,
  updateWhatsappAccountStatus,
  getEngineSessions,
  getEngineSession,
  requestEngineSessionStart,
  requestEngineSessionStop,
};