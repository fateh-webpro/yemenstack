module.exports = {
  apps: [
    {
      name: 'yemenstack-whatsapp-engine',
      script: 'src/index.js',
      cwd: __dirname,
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '300M',
      env: {
        NODE_ENV: 'development',
        ENGINE_NAME: 'yemenstack-whatsapp-engine',
        ENGINE_POLL_INTERVAL_MS: '5000',
        LARAVEL_BASE_URL: 'http://127.0.0.1:8000',
        ENGINE_API_TOKEN: '',
      },
    },
  ],
};
