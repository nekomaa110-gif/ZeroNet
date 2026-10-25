const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { buatArsipKiriman } = require('../arsip-kiriman');

const HARI = 24 * 60 * 60 * 1000;

function siapkan(opsi = {}) {
  const dir = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'arsip-uji-')), 'arsip');
  const jam = { t: Date.UTC(2026, 9, 9, 5, 0, 0) };
  const log = [];
  const logger = { warn: (...a) => log.push(JSON.stringify(a)) };
  const buat = () => buatArsipKiriman({ dir, ttlMs: opsi.ttlMs || 7 * HARI, maks: opsi.maks || 100, now: () => jam.t, logger });
  return { dir, jam, log, buat, arsip: buat() };
}

const PESAN_GAMBAR = {
  imageMessage: {
    mediaKey: Buffer.from('kunci-media-32-byte-xxxxxxxxxxxx'),
    directPath: '/v/t62.7118-24/abc',
    fileLength: 66324,
    caption: 'Voucher root',
  },
};

test('pesan yang disimpan bisa diambil lagi utuh, termasuk kunci media', () => {
  const { arsip } = siapkan();
  arsip.simpan('3EB01C7A86C30DF2B1981F', PESAN_GAMBAR);
  const hasil = arsip.ambil('3EB01C7A86C30DF2B1981F');
  assert.equal(Buffer.from(hasil.imageMessage.mediaKey).toString(), 'kunci-media-32-byte-xxxxxxxxxxxx');
  assert.equal(hasil.imageMessage.directPath, '/v/t62.7118-24/abc');
  assert.equal(String(hasil.imageMessage.fileLength), '66324');
  assert.equal(hasil.imageMessage.caption, 'Voucher root');
});

test('pesan tetap bisa diambil setelah gateway restart', () => {
  const s = siapkan();
  s.arsip.simpan('3EB06AAB59F496603ADBDF', { conversation: 'Ringkasan harian' });
  const setelahRestart = s.buat();
  assert.equal(setelahRestart.ambil('3EB06AAB59F496603ADBDF').conversation, 'Ringkasan harian');
});

test('pesan berumur 17 jam masih bisa diambil', () => {
  const s = siapkan();
  s.arsip.simpan('ID17JAM', { conversation: 'lama' });
  s.jam.t += 17 * 60 * 60 * 1000;
  s.arsip.simpan('IDBARU', { conversation: 'baru' });
  assert.equal(s.arsip.ambil('ID17JAM').conversation, 'lama');
});

test('pesan yang lewat masa simpan tidak dikembalikan dan berkasnya dihapus', () => {
  const s = siapkan();
  s.arsip.simpan('IDTUA', { conversation: 'tua' });
  s.jam.t += 7 * HARI + 1000;
  assert.equal(s.arsip.ambil('IDTUA'), undefined);
  assert.equal(fs.readdirSync(s.dir).length, 0);
});

test('menyimpan pesan baru membersihkan pesan yang lewat masa simpan', () => {
  const s = siapkan();
  s.arsip.simpan('IDTUA', { conversation: 'tua' });
  s.jam.t += 7 * HARI + 1000;
  s.arsip.simpan('IDBARU', { conversation: 'baru' });
  assert.deepEqual(fs.readdirSync(s.dir).sort(), ['IDBARU.bin']);
});

test('jumlah berkas dibatasi, yang paling lama dibuang dulu', () => {
  const s = siapkan({ maks: 3 });
  for (const id of ['ID1', 'ID2', 'ID3', 'ID4']) {
    s.arsip.simpan(id, { conversation: id });
    s.jam.t += 1000;
  }
  assert.equal(s.arsip.ambil('ID1'), undefined);
  assert.deepEqual(['ID2', 'ID3', 'ID4'].map((id) => s.arsip.ambil(id).conversation), ['ID2', 'ID3', 'ID4']);
});

test('id yang tidak aman ditolak dan tidak menyentuh berkas di luar direktori', () => {
  const s = siapkan();
  const rahasia = path.join(path.dirname(s.dir), 'rahasia.bin');
  fs.writeFileSync(rahasia, Buffer.from([1, 2, 3]));
  for (const id of ['../rahasia', 'a/b', '', '..', 'x'.repeat(65), null, undefined, 123]) {
    assert.equal(s.arsip.ambil(id), undefined);
    s.arsip.simpan(id, { conversation: 'x' });
  }
  assert.ok(fs.existsSync(rahasia));
  assert.equal(fs.readdirSync(s.dir).length, 0);
});

test('id yang tidak ada mengembalikan undefined', () => {
  const { arsip } = siapkan();
  assert.equal(arsip.ambil('TIDAKADA'), undefined);
});

test('berkas rusak tidak melempar galat dan dicatat', () => {
  const s = siapkan();
  s.arsip.simpan('IDRUSAK', { conversation: 'x' });
  fs.writeFileSync(path.join(s.dir, 'IDRUSAK.bin'), Buffer.from([0xff, 0xff, 0xff, 0xff]));
  assert.equal(s.arsip.ambil('IDRUSAK'), undefined);
  assert.match(s.log.join('\n'), /IDRUSAK/);
});

test('pesan kosong tidak disimpan', () => {
  const s = siapkan();
  s.arsip.simpan('IDKOSONG', null);
  s.arsip.simpan('IDKOSONG', undefined);
  assert.equal(fs.readdirSync(s.dir).length, 0);
});

test('direktori dan berkas hanya bisa dibaca pemiliknya', () => {
  const s = siapkan();
  s.arsip.simpan('IDIZIN', { conversation: 'x' });
  assert.equal(fs.statSync(s.dir).mode & 0o777, 0o700);
  assert.equal(fs.statSync(path.join(s.dir, 'IDIZIN.bin')).mode & 0o777, 0o600);
});
