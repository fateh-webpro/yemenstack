const { config } = require('./config');

const writeLog = (level, message, context = {}) => {
  const entry = {
    level,
    message,
    context,
    timestamp: new Date().toISOString(),
    engine: config.engineName,
  };

  console.log(JSON.stringify(entry));
};

const info = (message, context = {}) => {
  writeLog('info', message, context);
};

const warn = (message, context = {}) => {
  writeLog('warn', message, context);
};

const error = (message, context = {}) => {
  writeLog('error', message, context);
};

module.exports = {
  info,
  warn,
  error,
};