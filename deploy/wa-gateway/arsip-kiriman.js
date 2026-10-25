const fs = require('fs');
const path = require('path');
const { proto } = require('@whiskeysockets/baileys');

const ID_AMAN = /^[A-Za-z0-9]{1,64}$/;
const AKHIRAN = '.bin';

function buatArsipKiriman({ dir, ttlMs, maks, now = Date.now, logger }) {
  fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  fs.chmodSync(dir, 0o700);

  const berkas = (id) => path.join(dir, id + AKHIRAN);
  const idAman = (id) => typeof id === 'string' && ID_AMAN.test(id);
  const kedaluwarsa = (mtimeMs) => mtimeMs < now() - ttlMs;

  function daftar() {
    const isi = [];
    for (const nama of fs.readdirSync(dir)) {
      if (!nama.endsWith(AKHIRAN)) continue;
      try {
        isi.push({ nama, mtimeMs: fs.statSync(path.join(dir, nama)).mtimeMs });
      } catch {}
    }
    return isi;
  }

  function hapus(nama) {
    try { fs.unlinkSync(path.join(dir, nama)); } catch {}
  }

  function bersihkan() {
    const sisa = [];
    for (const b of daftar()) {
      if (kedaluwarsa(b.mtimeMs)) hapus(b.nama);
      else sisa.push(b);
    }
    sisa.sort((a, b) => a.mtimeMs - b.mtimeMs);
    while (sisa.length > maks) hapus(sisa.shift().nama);
  }

  function simpan(id, message) {
    if (!idAman(id) || !message) return;
    try {
      const data = Buffer.from(proto.Message.encode(message).finish());
      const sementara = berkas(id) + '.tmp-' + process.pid;
      fs.writeFileSync(sementara, data, { mode: 0o600 });
      const detik = now() / 1000;
      fs.utimesSync(sementara, detik, detik);
      fs.renameSync(sementara, berkas(id));
      bersihkan();
    } catch (e) {
      logger.warn({ id, err: e.message }, 'arsip_kiriman_gagal_simpan');
    }
  }

  function ambil(id) {
    if (!idAman(id)) return undefined;
    let stat;
    try {
      stat = fs.statSync(berkas(id));
    } catch {
      return undefined;
    }
    if (kedaluwarsa(stat.mtimeMs)) {
      hapus(id + AKHIRAN);
      return undefined;
    }
    try {
      return proto.Message.decode(fs.readFileSync(berkas(id)));
    } catch (e) {
      logger.warn({ id, err: e.message }, 'arsip_kiriman_rusak');
      return undefined;
    }
  }

  return { simpan, ambil };
}

module.exports = { buatArsipKiriman };
