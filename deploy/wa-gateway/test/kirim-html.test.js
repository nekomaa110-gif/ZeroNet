const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('path');
const express = require('express');
const { buatHandlerKirimHtml } = require('../kirim-html');
const { buatPemilihTujuan } = require('../alamat-tujuan');

const KONTRAK = require(path.join(__dirname, '..', '..', '..', 'tests', 'Fixtures', 'gateway-jawaban.json'));
const PNG = Buffer.from([0x89, 0x50, 0x4e, 0x47, 1, 2, 3]);

function kontrak(nama) {
  const k = KONTRAK.find((x) => x.nama === nama);
  assert.ok(k, 'entri kontrak ' + nama + ' tidak ada');
  return k;
}

async function siapkan(opsi = {}) {
  const log = [];
  const kirim = [];
  let jam = 0;
  const sock = {
    onWhatsApp: async () => {
      if (opsi.cekGagal) throw new Error('Timed Out');
      return [{ exists: opsi.bukanWa ? false : true, lid: opsi.lid }];
    },
    sendMessage: async (jid, isi) => {
      kirim.push({ jid, isi });
      if (opsi.kirimGagal) throw new Error('Connection Closed');
      return { key: { id: 'ID1' }, message: {} };
    },
  };
  const logger = {};
  for (const lv of ['info', 'warn', 'error']) logger[lv] = (...a) => log.push(JSON.stringify(a));
  const handler = buatHandlerKirimHtml({
    getSock: () => sock,
    waitReady: async () => opsi.tidakSiap ? false : true,
    renderHtml: opsi.renderHtml || (async () => { if (opsi.renderGagal) throw new Error('Navigation timeout of 15000 ms exceeded'); return PNG; }),
    normalizeJid: (n) => String(n) + '@s.whatsapp.net',
    rememberSent: () => {},
    jeda: async () => { if (opsi.lambat) jam += 41000; },
    now: () => jam,
    logger,
    ...(opsi.pilihTujuan ? { pilihTujuan: opsi.pilihTujuan } : {}),
  });
  const app = express();
  app.use(express.json({ limit: '256kb' }));
  app.post('/send-image-html', handler);
  const server = await new Promise((r) => { const s = app.listen(0, '127.0.0.1', () => r(s)); });
  const url = 'http://127.0.0.1:' + server.address().port + '/send-image-html';
  return { url, server, log, kirim };
}

async function post(url, body, signal) {
  const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), signal });
  let json = null;
  try { json = await res.json(); } catch {}
  return { status: res.status, json };
}

const BODY = { number: '6281200099999', html: '<p>RAHASIA-HTML</p>', caption: 'RAHASIA-CAPTION', viewport: { width: 402, scale: 2 } };

function cocokKontrak(hasil, nama) {
  const k = kontrak(nama);
  assert.equal(hasil.status, k.http);
  const harap = k.body.code ?? k.body.error;
  if (k.body.ok === true) {
    assert.equal(hasil.json.ok, true);
  } else {
    assert.equal(hasil.json.code ?? hasil.json.error, harap);
  }
}

test('sukses: 200 ok, id, png_bytes sesuai kontrak', async (t) => {
  const s = await siapkan();
  t.after(() => s.server.close());
  const h = await post(s.url, BODY);
  cocokKontrak(h, 'sukses');
  assert.equal(h.json.id, 'ID1');
  assert.equal(h.json.png_bytes, PNG.length);
  assert.equal(s.kirim.length, 1);
  assert.equal(s.kirim[0].isi.caption, 'RAHASIA-CAPTION');
});

test('html kosong atau lebih dari 200 KB: 400 invalid_request', async (t) => {
  const s = await siapkan();
  t.after(() => s.server.close());
  cocokKontrak(await post(s.url, { ...BODY, html: '' }), 'permintaan_salah');
  cocokKontrak(await post(s.url, { ...BODY, html: 'x'.repeat(200 * 1024 + 1) }), 'permintaan_salah');
  assert.equal(s.kirim.length, 0);
});

test('gateway belum siap: 503 wa_not_ready', async (t) => {
  const s = await siapkan({ tidakSiap: true });
  t.after(() => s.server.close());
  cocokKontrak(await post(s.url, BODY), 'belum_tersambung');
  assert.equal(s.kirim.length, 0);
});

test('nomor bukan WA: 404 number_not_on_whatsapp', async (t) => {
  const s = await siapkan({ bukanWa: true });
  t.after(() => s.server.close());
  cocokKontrak(await post(s.url, BODY), 'bukan_wa');
  assert.equal(s.kirim.length, 0);
});

test('cek nomor gagal: 500 check_failed, tidak mengirim', async (t) => {
  const s = await siapkan({ cekGagal: true });
  t.after(() => s.server.close());
  cocokKontrak(await post(s.url, BODY), 'cek_nomor_gagal');
  assert.equal(s.kirim.length, 0);
});

test('render gagal: 500 render_failed, tidak mengirim', async (t) => {
  const s = await siapkan({ renderGagal: true });
  t.after(() => s.server.close());
  cocokKontrak(await post(s.url, BODY), 'render_gagal');
  assert.equal(s.kirim.length, 0);
});

test('lewat tenggat 40 detik sebelum kirim: deadline_exceeded, tidak mengirim', async (t) => {
  const s = await siapkan({ lambat: true });
  t.after(() => s.server.close());
  cocokKontrak(await post(s.url, BODY), 'tenggat');
  assert.equal(s.kirim.length, 0);
});

test('sendMessage melempar: 500 send_failed', async (t) => {
  const s = await siapkan({ kirimGagal: true });
  t.after(() => s.server.close());
  cocokKontrak(await post(s.url, BODY), 'kirim_gagal');
  assert.equal(s.kirim.length, 1);
});

test('klien memutus saat render: pesan tidak dikirim', async (t) => {
  let lepas;
  const tungguRender = new Promise((r) => { lepas = r; });
  const s = await siapkan({ renderHtml: async () => { await tungguRender; return PNG; } });
  t.after(() => s.server.close());
  const ac = new AbortController();
  const permintaan = post(s.url, BODY, ac.signal).catch(() => 'putus');
  await new Promise((r) => setTimeout(r, 150));
  ac.abort();
  assert.equal(await permintaan, 'putus');
  await new Promise((r) => setTimeout(r, 100));
  lepas();
  await new Promise((r) => setTimeout(r, 200));
  assert.equal(s.kirim.length, 0);
});

test('log tidak memuat html maupun caption', async (t) => {
  const s1 = await siapkan();
  const s2 = await siapkan({ renderGagal: true });
  const s3 = await siapkan({ kirimGagal: true });
  t.after(() => { s1.server.close(); s2.server.close(); s3.server.close(); });
  await post(s1.url, BODY);
  await post(s2.url, BODY);
  await post(s3.url, BODY);
  const semua = [...s1.log, ...s2.log, ...s3.log].join('\n');
  assert.ok(semua.length > 0);
  assert.ok(!semua.includes('RAHASIA-HTML'));
  assert.ok(!semua.includes('RAHASIA-CAPTION'));
});

test('pemilih tujuan memberi LID: gambar dikirim ke alamat LID dan log mencatatnya', async (t) => {
  const s = await siapkan({ lid: '100000000000001@lid', pilihTujuan: buatPemilihTujuan('6281200099999') });
  t.after(() => s.server.close());
  cocokKontrak(await post(s.url, BODY), 'sukses');
  assert.equal(s.kirim[0].jid, '100000000000001@lid');
  assert.match(s.log.join('\n'), /100000000000001@lid/);
});

test('tanpa pemilih tujuan: gambar tetap dikirim ke alamat nomor HP', async (t) => {
  const s = await siapkan({ lid: '100000000000001@lid' });
  t.after(() => s.server.close());
  cocokKontrak(await post(s.url, BODY), 'sukses');
  assert.equal(s.kirim[0].jid, '6281200099999@s.whatsapp.net');
});
