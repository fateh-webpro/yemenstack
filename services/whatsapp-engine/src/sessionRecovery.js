const RECOVERABLE_SESSION_ERROR_PATTERNS = [
  'detached frame',
  'attempted to use detached frame',
  'execution context was destroyed',
  'target closed',
  'session closed',
  'protocol error',
  'most likely the page has been closed',
  'navigation failed because browser has disconnected',
  'connection closed',
  'browser has disconnected',
];

const getErrorMessage = (error) => {
  if (!error) {
    return '';
  }

  if (error instanceof Error) {
    return error.message || '';
  }

  return String(error);
};

const isRecoverableSessionError = (error) => {
  const normalizedMessage = getErrorMessage(error).toLowerCase();

  if (!normalizedMessage) {
    return false;
  }

  return RECOVERABLE_SESSION_ERROR_PATTERNS.some((pattern) => normalizedMessage.includes(pattern));
};

const calculateRecoveryDelayMs = (attempt, options = {}) => {
  const initialDelayMs = Number.isInteger(options.initialDelayMs) ? options.initialDelayMs : 5000;
  const maxDelayMs = Number.isInteger(options.maxDelayMs) ? options.maxDelayMs : 60000;
  const multipliers = [1, 3, 6, 12];
  const safeAttempt = Math.max(1, Number.parseInt(attempt, 10) || 1);
  const multiplier = multipliers[Math.min(safeAttempt - 1, multipliers.length - 1)];

  return Math.min(maxDelayMs, initialDelayMs * multiplier);
};

module.exports = {
  RECOVERABLE_SESSION_ERROR_PATTERNS,
  getErrorMessage,
  isRecoverableSessionError,
  calculateRecoveryDelayMs,
};