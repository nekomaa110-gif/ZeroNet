const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const CHROME_HOME = path.resolve(process.env.CHROME_HOME || path.join(__dirname, '.chrome-home'));
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

async function tutupBrowser() {
  try { await browserInstance?.close(); } catch {}
  browserInstance = null;
}

async function fotoHalaman(page, width, scale, viewport) {
  await page.evaluate(() => document.fonts && document.fonts.ready).catch(() => {});

  const contentH = await page.evaluate(() => {
    const b = document.body;
    return Math.ceil(Math.max(b.scrollHeight, b.getBoundingClientRect().height, 1));
  });
  if (contentH > 0 && contentH !== viewport.height) {
    await page.setViewport({ width, height: contentH, deviceScaleFactor: scale });
  }
  return await page.screenshot({ type: 'png', fullPage: true });
}

async function renderOnce(url, viewport) {
  const browser = await getBrowser();
  const page = await browser.newPage();
  try {
    const width = viewport.width || 420;
    const scale = viewport.scale || 2;
    await page.setViewport({ width, height: viewport.height || 800, deviceScaleFactor: scale });
    const resp = await page.goto(url, { waitUntil: 'networkidle0', timeout: 20000 });
    if (resp && resp.status() >= 400) {
      const e = new Error('halaman menjawab ' + resp.status());
      e.code = 'http_' + resp.status();
      throw e;
    }
    return await fotoHalaman(page, width, scale, viewport);
  } finally {
    try { await page.close(); } catch {}
  }
}

async function renderUrlToPng(url, viewport = {}, logger = null) {
  try {
    return await renderOnce(url, viewport);
  } catch (e) {
    if (typeof e.code === 'string' && e.code.startsWith('http_')) throw e;
    logger?.warn({ err: e.message, url }, 'render_retry');
    await tutupBrowser();
    return await renderOnce(url, viewport);
  }
}

async function renderHtmlToPng(html, viewport = {}) {
  const browser = await getBrowser();
  const page = await browser.newPage();
  try {
    const width = viewport.width || 420;
    const scale = viewport.scale || 2;
    await page.setViewport({ width, height: viewport.height || 800, deviceScaleFactor: scale });
    await page.setContent(html, { waitUntil: 'networkidle0', timeout: 15000 });
    return await fotoHalaman(page, width, scale, viewport);
  } finally {
    try { await page.close(); } catch {}
  }
}

module.exports = { CHROME_HOME, getBrowser, tutupBrowser, renderUrlToPng, renderHtmlToPng };
