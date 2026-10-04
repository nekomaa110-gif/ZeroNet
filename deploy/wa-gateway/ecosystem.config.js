module.exports = {
  apps: [{
    name: 'wa-gateway',
    script: 'server.js',
    cwd: '/opt/wa-gateway',
    instances: 1,
    exec_mode: 'fork',
    max_memory_restart: '600M',
    autorestart: true,
    restart_delay: 3000,
    out_file: '/opt/wa-gateway/logs/pm2-out.log',
    error_file: '/opt/wa-gateway/logs/pm2-err.log',
    env: { NODE_ENV: 'production', PUPPETEER_CACHE_DIR: '/opt/wa-gateway/.puppeteer-cache', HOME: '/opt/wa-gateway' },
  }],
};
