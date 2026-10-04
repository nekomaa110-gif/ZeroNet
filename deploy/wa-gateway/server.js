require('dotenv').config();
const fs = require('fs');
const path = require('path');
const express = require('express');
const rateLimit = require('express-rate-limit');
const pino = require('pino');
const QRCode = require('qrcode');
const puppeteer = require('puppeteer');
const {
  default: makeWASocket,
  useMultiFileAuthState,
  fetchLatestBaileysVersion,
  DisconnectReason,
  Browsers,
} = require('@whiskeysockets/baileys');

const PORT = parseInt(process.env.PORT || '3001', 10);
const HOST = process.env.HOST || '127.0.0.1';
const API_KEY = process.env.API_KEY;
const SESSION_DIR = path.resolve(process.env.SESSION_DIR || './auth');
const LOG_DIR = path.resolve(process.env.LOG_DIR || './logs');
const MIN_DELAY = parseInt(process.env.MIN_DELAY_MS || '1500', 10);
const MAX_DELAY = parseInt(process.env.MAX_DELAY_MS || '4000', 10);

const VERSION_FETCH_TIMEOUT = 8000;
const STUCK_MS = 90 * 1000;
const QR_STALE_MS = 3 * 60 * 1000;
const MAX_FAIL_BEFORE_RESET = 5;
const SEND_WAIT_MS = 20 * 1000;

if (!API_KEY || API_KEY.length < 24) {
  console.error('FATAL: API_KEY belum di-set / terlalu pendek');
  process.exit(1);
}
fs.mkdirSync(SESSION_DIR, { recursive: true });
fs.mkdirSync(LOG_DIR, { recursive: true });

const logger = pino(
  { level: 'info' },
  pino.destination({ dest: path.join(LOG_DIR, 'gateway.log'), sync: true })
);

const NOISE = /^(Closing session|Closing open session|Session error|Removing old closed session)/;
for (const m of ['log', 'error', 'warn']) {
  const orig = console[m].bind(console);
  console[m] = (...a) => {
    if (typeof a[0] === 'string' && NOISE.test(a[0])) return;
    orig(...a);
  };
}

let sock = null;
let sockGen = 0;
let starting = false;
let reconnectTimer = null;

let connectionStatus = 'disconnected';
let lastQR = null;
let lastQRRaw = null;
let qrAt = 0;
let reconnectAttempts = 0;
let failedSinceOpen = 0;
let lastConnectedAt = null;
let lastDisconnectCode = null;
let lastDisconnectReason = null;
let lastStateChangeAt = Date.now();
let cachedVersion = null;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const jitter = () => MIN_DELAY + Math.floor(Math.random() * (MAX_DELAY - MIN_DELAY));

const SENT_CACHE_MAX = 400;
const SENT_CACHE_TTL = 60 * 60 * 1000;
const sentCache = new Map();

function rememberSent(id, message) {
  if (!id || !message) return;
  sentCache.set(id, { message, at: Date.now() });
  const cutoff = Date.now() - SENT_CACHE_TTL;
  for (const [k, v] of sentCache) {
    if (v.at >= cutoff && sentCache.size <= SENT_CACHE_MAX) break;
    sentCache.delete(k);
  }
}

function setStatus(s) {
  if (connectionStatus !== s) {
    logger.info({ from: connectionStatus, to: s }, 'status change');
    connectionStatus = s;
  }
  lastStateChangeAt = Date.now();
}

function normalizeJid(number) {
  let n = String(number).replace(/[^0-9]/g, '');
  if (n.startsWith('0')) n = '62' + n.slice(1);
  if (n.startsWith('620')) n = '62' + n.slice(3);
  return n + '@s.whatsapp.net';
}

function hasCreds() {
  try {
    const c = JSON.parse(fs.readFileSync(path.join(SESSION_DIR, 'creds.json'), 'utf8'));
    return !!c?.me?.id;
  } catch { return false; }
}

function wipeSession(reason) {
  try {
    const backup = path.join(path.dirname(SESSION_DIR), `auth.bad.${Date.now()}`);
    fs.renameSync(SESSION_DIR, backup);
    fs.mkdirSync(SESSION_DIR, { recursive: true });
    logger.warn({ reason, backup }, 'session wiped, QR baru akan digenerate');

    const parent = path.dirname(SESSION_DIR);
    const olds = fs.readdirSync(parent).filter((f) => f.startsWith('auth.bad.')).sort();
    for (const f of olds.slice(0, Math.max(0, olds.length - 3))) {
      fs.rmSync(path.join(parent, f), { recursive: true, force: true });
    }
  } catch (e) {
    logger.error({ err: e.message, reason }, 'wipeSession failed');
    try {
      for (const f of fs.readdirSync(SESSION_DIR)) {
        fs.rmSync(path.join(SESSION_DIR, f), { recursive: true, force: true });
      }
    } catch {}
  }
  lastQR = null; lastQRRaw = null; qrAt = 0;
}

function teardown() {
  const s = sock;
  sock = null;
  if (!s) return;
  try { s.ev.removeAllListeners(); } catch {}
  try { s.ws?.removeAllListeners?.(); } catch {}
  try { s.end(undefined); } catch {}
}

async function resolveVersion() {
  try {
    const res = await Promise.race([
      fetchLatestBaileysVersion(),
      new Promise((_, rej) => setTimeout(() => rej(new Error('version_fetch_timeout')), VERSION_FETCH_TIMEOUT)),
    ]);
    cachedVersion = res.version;
    return res.version;
  } catch (e) {
    const fallback = cachedVersion || require('@whiskeysockets/baileys/lib/Defaults/baileys-version.json').version;
    logger.warn({ err: e.message, fallback }, 'fetchLatestBaileysVersion gagal, pakai fallback');
    return fallback;
  }
}

function scheduleReconnect(delayMs, reason) {
  if (reconnectTimer) clearTimeout(reconnectTimer);
  logger.info({ delayMs, reason, attempt: reconnectAttempts }, 'reconnect dijadwalkan');
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    startSocket().catch((e) => {
      logger.error({ err: e.message }, 'startSocket gagal');
      setStatus('disconnected');
      scheduleReconnect(Math.min(60000, 2000 * Math.pow(2, reconnectAttempts++)), 'start_failed');
    });
  }, delayMs);
}

async function startSocket() {
  if (starting) { logger.info('startSocket diabaikan, masih ada yang jalan'); return; }
  starting = true;
  const gen = ++sockGen;
  try {
    teardown();
    setStatus('connecting');

    const { state, saveCreds } = await useMultiFileAuthState(SESSION_DIR);
    const version = await resolveVersion();

    const s = makeWASocket({
      version,
      auth: state,
      printQRInTerminal: false,
      browser: Browsers.ubuntu('Chrome'),
      syncFullHistory: false,
      markOnlineOnConnect: false,
      connectTimeoutMs: 30000,
      keepAliveIntervalMs: 25000,
      retryRequestDelayMs: 1000,
      getMessage: async (key) => {
        const hit = sentCache.get(key.id);
        logger.info({ id: key.id, chat: key.remoteJid, from: key.participant, hit: !!hit }, 'retry request');
        return hit?.message;
      },
      logger: pino({ level: 'silent' }),
    });
    sock = s;

    s.ev.on('creds.update', saveCreds);

    s.ev.on('messages.update', (ups) => {
      if (gen !== sockGen) return;
      for (const u of ups) {
        if (u.update?.status !== undefined) {
          logger.info({ id: u.key?.id, chat: u.key?.remoteJid, status: u.update.status }, 'message status');
        }
      }
    });

    s.ev.on('connection.update', async (u) => {
      if (gen !== sockGen) return;
      const { connection, lastDisconnect, qr } = u;

      if (qr) {
        lastQRRaw = qr;
        lastQR = await QRCode.toDataURL(qr);
        qrAt = Date.now();
        reconnectAttempts = 0;
        setStatus('qr');
        logger.info('QR digenerate, scan via panel admin → WhatsApp Gateway');
      }

      if (connection === 'open') {
        lastQR = null; lastQRRaw = null; qrAt = 0;
        reconnectAttempts = 0;
        failedSinceOpen = 0;
        lastConnectedAt = Date.now();
        lastDisconnectCode = null;
        lastDisconnectReason = null;
        setStatus('open');
        logger.info({ user: s.user?.id }, 'WA connected');
      }

      if (connection === 'close') {
        const code = lastDisconnect?.error?.output?.statusCode;
        const named = DisconnectReason[code] || 'unknown';
        lastDisconnectCode = code ?? null;
        lastDisconnectReason = named;
        failedSinceOpen++;
        setStatus('disconnected');
        logger.warn({ code, named, failedSinceOpen }, 'WA disconnected');

        teardown();

        const fatal = [401, 403, 405, 411].includes(code);
        const exhausted = failedSinceOpen >= MAX_FAIL_BEFORE_RESET && hasCreds();

        if (fatal || exhausted) {
          wipeSession(fatal ? `fatal_${code}` : 'too_many_failures');
          reconnectAttempts = 0;
          failedSinceOpen = 0;
          scheduleReconnect(1500, 'after_wipe');
          return;
        }

        if (code === DisconnectReason.restartRequired) {
          scheduleReconnect(500, 'restart_required');
          return;
        }

        scheduleReconnect(Math.min(60000, 2000 * Math.pow(2, reconnectAttempts++)), `close_${code}`);
      }
    });
  } finally {
    starting = false;
  }
}

function forceRestart(reason) {
  logger.warn({ reason, status: connectionStatus }, 'force restart socket');
  if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
  sockGen++;
  teardown();
  starting = false;
  setStatus('disconnected');
  scheduleReconnect(500, reason);
}

setInterval(() => {
  const idle = Date.now() - lastStateChangeAt;
  if (connectionStatus === 'open') return;
  if (connectionStatus === 'qr') {
    if (Date.now() - qrAt > QR_STALE_MS) forceRestart('qr_stale');
    return;
  }
  if (idle > STUCK_MS && !reconnectTimer) forceRestart('stuck_' + connectionStatus);
  else if (idle > STUCK_MS * 4) forceRestart('stuck_hard_' + connectionStatus);
}, 30000);

async function waitReady(ms) {
  const until = Date.now() + ms;
  while (Date.now() < until) {
    if (connectionStatus === 'open') return true;
    if (connectionStatus === 'qr') return false;
    await sleep(500);
  }
  return connectionStatus === 'open';
}

const app = express();
app.use(express.json({ limit: '256kb' }));
app.set('trust proxy', 'loopback');

const apiAuth = (req, res, next) => {
  if (req.get('x-api-key') !== API_KEY) return res.status(401).json({ ok: false, error: 'unauthorized' });
  next();
};

const sendLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: parseInt(process.env.RATE_LIMIT_PER_MINUTE || '20', 10),
  standardHeaders: true,
  legacyHeaders: false,
});

function statePayload() {
  return {
    status: connectionStatus,
    user: sock?.user?.id || null,
    has_qr: !!lastQR,
    attempts: reconnectAttempts,
    failed_since_open: failedSinceOpen,
    last_disconnect_code: lastDisconnectCode,
    last_disconnect_reason: lastDisconnectReason,
    last_connected_at: lastConnectedAt,
    seconds_in_state: Math.round((Date.now() - lastStateChangeAt) / 1000),
    uptime: process.uptime(),
  };
}

app.get('/status', apiAuth, (req, res) => {
  const payload = { ok: true, ...statePayload() };
  if (req.query.qr === '1') payload.qr = lastQR;
  res.json(payload);
});

app.get('/qr', apiAuth, (req, res) => {
  res.json({ ok: true, status: connectionStatus, qr: lastQR });
});

app.post('/reconnect', apiAuth, (req, res) => {
  forceRestart('api_reconnect');
  res.json({ ok: true, status: connectionStatus });
});

app.post('/reset', apiAuth, async (req, res) => {
  await doLogout('api_reset');
  res.json({ ok: true, status: connectionStatus });
});

async function doLogout(reason) {
  try { if (connectionStatus === 'open') await sock?.logout(); } catch (e) {
    logger.warn({ err: e.message }, 'logout call gagal, lanjut wipe');
  }
  if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
  sockGen++;
  teardown();
  starting = false;
  wipeSession(reason);
  reconnectAttempts = 0;
  failedSinceOpen = 0;
  setStatus('disconnected');
  scheduleReconnect(500, reason);
}

const CHROME_HOME = path.resolve(__dirname, '.chrome-home');
fs.mkdirSync(path.join(CHROME_HOME, 'profile'), { recursive: true });

let browserInstance = null;
async function getBrowser() {
  if (browserInstance && browserInstance.isConnected && browserInstance.isConnected()) {
    return browserInstance;
  }
  try { if (browserInstance) await browserInstance.close(); } catch {}
  browserInstance = await puppeteer.launch({
    headless: 'new',
    args: [
      '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage',
      '--disable-gpu', '--no-zygote', '--hide-scrollbars',
      '--disable-crashpad', '--disable-breakpad', '--disable-crash-reporter',
    ],
    userDataDir: path.join(CHROME_HOME, 'profile'),
    env: {
      ...process.env,
      HOME: CHROME_HOME,
      XDG_CONFIG_HOME: path.join(CHROME_HOME, '.config'),
      XDG_CACHE_HOME: path.join(CHROME_HOME, '.cache'),
    },
  });
  browserInstance.on('disconnected', () => { browserInstance = null; });
  return browserInstance;
}

async function renderOnce(url, viewport) {
  const browser = await getBrowser();
  const page = await browser.newPage();
  try {
    const width = viewport.width || 420;
    const scale = viewport.scale || 2;
    await page.setViewport({ width, height: viewport.height || 800, deviceScaleFactor: scale });
    await page.goto(url, { waitUntil: 'networkidle0', timeout: 20000 });
    await page.evaluate(() => document.fonts && document.fonts.ready).catch(() => {});

    const contentH = await page.evaluate(() => {
      const b = document.body;
      return Math.ceil(Math.max(b.scrollHeight, b.getBoundingClientRect().height, 1));
    });
    if (contentH > 0 && contentH !== viewport.height) {
      await page.setViewport({ width, height: contentH, deviceScaleFactor: scale });
    }
    return await page.screenshot({ type: 'png', fullPage: true });
  } finally {
    try { await page.close(); } catch {}
  }
}

async function renderUrlToPng(url, viewport = {}) {
  try {
    return await renderOnce(url, viewport);
  } catch (e) {
    logger.warn({ err: e.message, url }, 'render_retry');
    try { await browserInstance?.close(); } catch {}
    browserInstance = null;
    return await renderOnce(url, viewport);
  }
}

app.post('/send', apiAuth, sendLimiter, async (req, res) => {
  const { number, message } = req.body || {};
  if (!number || !message) return res.status(400).json({ ok: false, error: 'number & message required' });
  if (!(await waitReady(SEND_WAIT_MS))) {
    return res.status(503).json({ ok: false, error: 'wa_not_ready', status: connectionStatus, needs_qr: connectionStatus === 'qr' });
  }
  try {
    const jid = normalizeJid(number);
    const [check] = await sock.onWhatsApp(jid);
    if (!check?.exists) return res.status(404).json({ ok: false, error: 'number_not_on_whatsapp' });
    await sleep(jitter());
    const r = await sock.sendMessage(jid, { text: String(message) });
    rememberSent(r.key.id, r.message);
    logger.info({ to: jid, id: r.key.id }, 'sent');
    res.json({ ok: true, id: r.key.id });
  } catch (e) {
    logger.error({ err: e.message }, 'send_failed');
    res.status(500).json({ ok: false, error: e.message });
  }
});

app.post('/send-image-url', apiAuth, sendLimiter, async (req, res) => {
  const { number, url, caption, viewport } = req.body || {};
  if (!number || !url) return res.status(400).json({ ok: false, error: 'number & url required' });
  if (!/^https?:\/\//.test(url)) return res.status(400).json({ ok: false, error: 'invalid url' });
  if (!(await waitReady(SEND_WAIT_MS))) {
    return res.status(503).json({ ok: false, error: 'wa_not_ready', status: connectionStatus, needs_qr: connectionStatus === 'qr' });
  }
  try {
    const jid = normalizeJid(number);
    const [check] = await sock.onWhatsApp(jid);
    if (!check?.exists) return res.status(404).json({ ok: false, error: 'number_not_on_whatsapp' });

    const png = await renderUrlToPng(url, viewport || {});
    await sleep(jitter());
    const r = await sock.sendMessage(jid, {
      image: png,
      caption: caption ? String(caption) : undefined,
    });
    rememberSent(r.key.id, r.message);
    logger.info({ to: jid, id: r.key.id, url, png_bytes: png.length }, 'sent_image_url');
    res.json({ ok: true, id: r.key.id, png_bytes: png.length });
  } catch (e) {
    logger.error({ err: e.message, url }, 'send_image_url_failed');
    res.status(500).json({ ok: false, error: e.message });
  }
});

app.listen(PORT, HOST, () => logger.info(`listening on ${HOST}:${PORT}`));
startSocket().catch((e) => {
  logger.error({ err: e.message }, 'startSocket awal gagal');
  scheduleReconnect(3000, 'boot_failed');
});

process.on('unhandledRejection', (e) => logger.error({ err: e?.message || String(e) }, 'unhandledRejection'));
process.on('uncaughtException', (e) => logger.error({ err: e?.message, stack: e?.stack }, 'uncaughtException'));
process.on('SIGTERM', () => { try { sock?.end(); } catch {} process.exit(0); });
