const test = require('node:test');
const assert = require('node:assert/strict');
const {
  isRecoverableSessionError,
  calculateRecoveryDelayMs,
} = require('../src/sessionRecovery');

test('detached frame and browser lifecycle errors are recoverable session errors', () => {
  assert.equal(isRecoverableSessionError(new Error('Attempted to use detached Frame')), true);
  assert.equal(isRecoverableSessionError(new Error('Execution context was destroyed')), true);
  assert.equal(isRecoverableSessionError(new Error('Target closed')), true);
  assert.equal(isRecoverableSessionError(new Error('Session closed')), true);
  assert.equal(isRecoverableSessionError(new Error('Protocol error (Runtime.callFunctionOn): Target closed')), true);
  assert.equal(isRecoverableSessionError(new Error('Navigation failed because browser has disconnected')), true);
});

test('data and recipient errors are not treated as recoverable session errors', () => {
  assert.equal(isRecoverableSessionError(new Error('Recipient is not available on WhatsApp or could not be resolved.')), false);
  assert.equal(isRecoverableSessionError(new Error('Invalid phone number format')), false);
  assert.equal(isRecoverableSessionError(new Error('Message body is required')), false);
  assert.equal(isRecoverableSessionError(null), false);
});

test('recovery delay uses the configured backoff schedule', () => {
  const options = { initialDelayMs: 5000, maxDelayMs: 60000 };

  assert.equal(calculateRecoveryDelayMs(1, options), 5000);
  assert.equal(calculateRecoveryDelayMs(2, options), 15000);
  assert.equal(calculateRecoveryDelayMs(3, options), 30000);
  assert.equal(calculateRecoveryDelayMs(4, options), 60000);
  assert.equal(calculateRecoveryDelayMs(5, options), 60000);
});
