const test = require('node:test');
const assert = require('node:assert/strict');
const { config } = require('../src/config');
const indexModule = require('../src/index');

test.afterEach(() => {
  config.multiSessionEnabled = false;
});

test('requiring index does not auto-start any runtime', () => {
  assert.equal(typeof indexModule.startEngine, 'function');
  assert.equal(typeof indexModule.createEngineRuntime, 'function');
});

test('feature flag false selects the legacy runtime only', () => {
  config.multiSessionEnabled = false;
  let legacyCreated = 0;
  let multiCreated = 0;

  const runtime = indexModule.createEngineRuntime({
    createLegacyRuntime: () => {
      legacyCreated += 1;
      return { mode: 'legacy' };
    },
    createMultiSessionRuntime: () => {
      multiCreated += 1;
      return { mode: 'multi' };
    },
  });

  assert.equal(runtime.mode, 'legacy');
  assert.equal(legacyCreated, 1);
  assert.equal(multiCreated, 0);
});

test('feature flag true selects the multi-session runtime only', () => {
  config.multiSessionEnabled = true;
  let legacyCreated = 0;
  let multiCreated = 0;

  const runtime = indexModule.createEngineRuntime({
    createLegacyRuntime: () => {
      legacyCreated += 1;
      return { mode: 'legacy' };
    },
    createMultiSessionRuntime: () => {
      multiCreated += 1;
      return { mode: 'multi' };
    },
  });

  assert.equal(runtime.mode, 'multi');
  assert.equal(legacyCreated, 0);
  assert.equal(multiCreated, 1);
});