const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');

const AKAR = path.join(__dirname, '..');

function modulLokal(berkas, terkumpul = new Set()) {
  const isi = fs.readFileSync(path.join(AKAR, berkas), 'utf8');
  for (const m of isi.matchAll(/require\('\.\/([a-z0-9-]+)'\)/g)) {
    const nama = m[1] + '.js';
    if (!terkumpul.has(nama)) {
      terkumpul.add(nama);
      modulLokal(nama, terkumpul);
    }
  }
  return terkumpul;
}

const README = fs.readFileSync(path.join(AKAR, 'README.md'), 'utf8');
const WAJIB = ['server.js', ...modulLokal('server.js')];

test('setiap modul yang dipakai server.js tercantum di kedua perulangan deploy', () => {
  const perulangan = [...README.matchAll(/^for f in ([^;]+); do/gm)].map((m) => m[1].trim().split(/\s+/));
  assert.equal(perulangan.length, 2);
  for (const daftar of perulangan) {
    assert.deepEqual([...daftar].sort(), [...WAJIB].sort());
  }
});

test('cek require sebelum restart memuat setiap modul selain server.js', () => {
  const baris = README.split('\n').find((b) => b.includes('node -e "require('));
  assert.ok(baris);
  for (const f of WAJIB.filter((f) => f !== 'server.js')) {
    assert.ok(baris.includes(`require('/opt/wa-gateway/${f.replace(/\.js$/, '')}')`), f);
  }
});

test('langkah pasang dari nol menyalin setiap modul', () => {
  const baris = README.split('\n').find((b) => b.startsWith('sudo cp /var/www/zeronet/deploy/wa-gateway/{'));
  assert.ok(baris);
  for (const f of WAJIB) assert.ok(baris.includes(f), f);
});
