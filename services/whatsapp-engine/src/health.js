const { config } = require('./config');

const payload = {
  success: true,
  engine: config.engineName,
  service: 'whatsapp-gateway',
  status: 'ok',
  environment: config.nodeEnv,
  laravel_base_url: config.laravelBaseUrl,
  poll_interval_ms: config.pollIntervalMs,
};

console.log(JSON.stringify(payload, null, 2));