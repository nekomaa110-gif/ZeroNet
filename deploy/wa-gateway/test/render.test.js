const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');

process.env.CHROME_HOME = fs.mkdtempSync(path.join(os.tmpdir(), 'zn-chrome-tes-'));
const render = require('../render');

let server;
let base;
const hitungan = {};

function lebarPng(buf) {
  return buf.readUInt32BE(16);
}

function isPng(buf) {
  return Buffer.isBuffer(buf) || buf instanceof Uint8Array
    ? Buffer.from(buf).subarray(0, 4).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47]))
    : false;
}

before(async () => {
  server = http.createServer((req, res) => {
    const p = req.url.split('?')[0];
    if (p === '/favicon.ico') { res.writeHead(404); return res.end(); }
    hitungan[p] = (hitungan[p] || 0) + 1;
    const kode = { '/ok': 200, '/404': 404, '/429': 429, '/500': 500 }[p] || 404;
    res.writeHead(kode, { 'Content-Type': 'text/html' });
    res.end('<!doctype html><html><body style="margin:0"><p style="padding:20px">halaman ' + kode + '</p></body></html>');
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  base = 'http://127.0.0.1:' + server.address().port;
});

after(async () => {
  await render.tutupBrowser();
  await new Promise((r) => server.close(r));
  fs.rmSync(process.env.CHROME_HOME, { recursive: true, force: true });
});

test('profil Chrome tes ada di direktori sementara, bukan di repo', () => {
  assert.ok(render.CHROME_HOME.startsWith(os.tmpdir()));
});

test('halaman 200 menghasilkan PNG', async () => {
  const png = await render.renderUrlToPng(base + '/ok', { width: 400, scale: 1 });
  assert.ok(isPng(png));
});

for (const kode of [404, 429, 500]) {
  test('halaman ' + kode + ' ditolak tanpa mencoba ulang', async () => {
    await assert.rejects(render.renderUrlToPng(base + '/' + kode, { width: 400, scale: 1 }), (e) => e.code === 'http_' + kode);
    assert.equal(hitungan['/' + kode], 1);
  });
}

test('HTML voucher dengan kredensial panjang menghasilkan PNG lebar 804 px', async () => {
  const u = 'u'.repeat(60) + '&<">';
  const p = 'P'.repeat(124) + "'<&>";
  const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  const html = '<!doctype html><html><head><meta charset="utf-8"><style>*{margin:0;padding:0;box-sizing:border-box}body{padding:12px;font-family:monospace}'
    + '.voucher{width:100mm;margin:0 auto;border:1.5px dashed #1A1A1A;padding:14px 16px 12px}.v{font-size:20px;font-weight:700;word-break:break-all}</style></head>'
    + '<body><div class="voucher"><div class="v">' + esc(u) + '</div><div class="v">' + esc(p) + '</div></div></body></html>';
  const png = await render.renderHtmlToPng(html, { width: 402, scale: 2 });
  assert.ok(isPng(png));
  assert.equal(lebarPng(Buffer.from(png)), 804);
});
