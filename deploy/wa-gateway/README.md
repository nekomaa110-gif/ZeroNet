# WA Gateway

Service Node (Baileys) yang dipakai Laravel untuk kirim WhatsApp.
Butuh Node.js 20 atau lebih baru (`engines` Baileys di `package-lock.json`).
Gateway berjalan sebagai service terpisah di luar folder aplikasi Laravel. Contoh di bawah memasangnya di `/opt/wa-gateway`.
Folder ini menyimpan source-nya di repo.

## Hubungannya dengan Laravel

Laravel tidak pernah bicara ke WhatsApp langsung. Alurnya:

```
Browser admin → route Laravel (auth + role:admin)
              → App\Services\WhatsAppService (pakai WA_GATEWAY_KEY)
              → http://127.0.0.1:3001  (service ini)
              → WhatsApp
```

Gateway hanya bind ke `127.0.0.1` dan tidak punya pintu publik.
Semua endpoint dijaga header `x-api-key`:

| Endpoint | Fungsi |
|---|---|
| `GET /status` | status koneksi; `?qr=1` untuk ikut sertakan QR |
| `GET /qr` | QR terakhir (data-URI) |
| `POST /reconnect` | sambung ulang, sesi dipertahankan (tidak perlu scan) |
| `POST /reset` | putus sesi + hapus kredensial → QR baru (harus scan) |
| `POST /send` | kirim teks |
| `POST /send-image-url` | render URL jadi PNG lewat Puppeteer, lalu kirim; halaman yang menjawab 400 ke atas tidak dikirim (`code: http_<status>`) |
| `POST /send-image-html` | render HTML (maks 200 KB) jadi PNG, lalu kirim; kode galat `invalid_request`, `check_failed`, `render_failed`, `deadline_exceeded`, `send_failed` |

Scan QR dilakukan dari modal di panel admin: **`/admin/whatsapp` → tombol "Panel gateway"**.
Halaman `/wa-admin` lama sudah dihapus berikut basic-auth-nya.

## Deploy / update

Gateway terdiri dari lima file: `server.js`, `render.js`, `kirim-html.js`, `arsip-kiriman.js`, dan
`alamat-tujuan.js`. Salin semuanya bersamaan; `server.js` saja akan gagal start karena modul yang
di-require tidak ada. Tes `test/readme-deploy.test.js` menjaga daftar ini tetap lengkap.

```bash
sudo -u www-data mkdir -p /opt/wa-gateway/cadangan
for f in server.js render.js kirim-html.js arsip-kiriman.js alamat-tujuan.js; do
  [ -f /opt/wa-gateway/$f ] && sudo -u www-data cp -p /opt/wa-gateway/$f /opt/wa-gateway/cadangan/$f.$(date +%Y%m%d%H%M)
  sudo cp deploy/wa-gateway/$f /opt/wa-gateway/$f
  sudo chown www-data:www-data /opt/wa-gateway/$f
done
for f in server.js render.js kirim-html.js arsip-kiriman.js alamat-tujuan.js; do node --check /opt/wa-gateway/$f; done
cd /opt/wa-gateway && sudo -u www-data node -e "require('/opt/wa-gateway/render'); require('/opt/wa-gateway/kirim-html'); require('/opt/wa-gateway/arsip-kiriman'); require('/opt/wa-gateway/alamat-tujuan')"
sudo -u www-data PM2_HOME=/var/www/.pm2 pm2 restart wa-gateway
```

`node --check` hanya memeriksa sintaks; baris `node -e "require(...)"` memastikan modulnya
benar-benar bisa dimuat sebelum restart. Rollback: salin balik file dari `/opt/wa-gateway/cadangan/`
(versi lama gateway hanya butuh file yang dulu ada; file baru yang tertinggal tidak dimuat), lalu restart PM2 yang sama.

Tes gateway (tidak mengirim WA, Chrome memakai profil di direktori sementara):

```bash
cd deploy/wa-gateway
NODE_PATH=/opt/wa-gateway/node_modules PUPPETEER_CACHE_DIR=/opt/wa-gateway/.puppeteer-cache node --test test/
```

PM2-nya milik **www-data**, bukan user biasa, jadi `pm2 list` biasa tidak akan
menampilkannya. Selalu pakai prefix `sudo -u www-data PM2_HOME=/var/www/.pm2`.

## Pasang dari nol

```bash
sudo mkdir -p /opt/wa-gateway && cd /opt/wa-gateway
sudo cp /var/www/zeronet/deploy/wa-gateway/{server.js,render.js,kirim-html.js,arsip-kiriman.js,alamat-tujuan.js,package.json,package-lock.json,ecosystem.config.js} .
sudo cp /var/www/zeronet/deploy/wa-gateway/.env.example .env   # lalu isi API_KEY
sudo chown -R www-data:www-data /opt/wa-gateway
sudo chmod 600 /opt/wa-gateway/.env
sudo -u www-data PUPPETEER_CACHE_DIR=/opt/wa-gateway/.puppeteer-cache npm ci
sudo -u www-data PM2_HOME=/var/www/.pm2 pm2 start ecosystem.config.js
```

Lalu buka panel admin dan scan QR.

## Yang TIDAK boleh masuk git

`.env` (API key), `auth/` (kredensial sesi WhatsApp, setara password),
`arsip-kiriman/` (isi pesan terkirim 7 hari terakhir), `logs/`, `node_modules/`,
`.puppeteer-cache/`.

## Kirim ulang dan alamat LID

HP penerima yang gagal membuka pesan meminta kirim ulang (retry receipt), sering
berjam-jam kemudian saat HP baru online. Gateway melayaninya dari `arsip-kiriman/`:
satu file protobuf per id pesan, mode 600, dihapus setelah `ARSIP_KIRIMAN_HARI`
(bawaan 7) hari atau bila lebih dari 5000 file. Dulu cache ini hanya 1 jam di memori
dan hilang tiap restart, sehingga retry yang telat berakhir "Menunggu pesan ini".

`KIRIM_LID` mengatur alamat tujuan: kosong = alamat nomor HP (perilaku lama), daftar
nomor dipisah koma = nomor itu dikirim ke alamat LID bila WhatsApp memberikannya,
`semua` = setiap nomor. Kiriman ke alamat nomor HP membuat HP yang sudah memakai LID
gagal membuka salinan pertama dan selalu bergantung pada retry.

## Ketahanan (kenapa kodenya seperti sekarang)

Ditulis setelah gateway mati diam-diam 8,6 hari (29 Jul – 6 Agu 2026):
proses tetap hidup dan `/status` menjawab `connecting`, tapi nol koneksi
ke WhatsApp. Empat hal yang mencegah terulang:

1. **Watchdog 30 detik.** Status non-`open` lebih dari 90 detik → restart paksa.
   Tanpa ini, satu `await` yang menggantung membekukan service selamanya.
2. **Timeout `fetchLatestBaileysVersion()`.** Fungsi ini tidak punya timeout
   bawaan; kalau jaringan blackhole, `startSocket()` menggantung permanen.
   Sekarang dibatasi 8 detik lalu fallback ke versi bundled.
3. **Wipe sesi untuk kode fatal 401/403/405/411.** Baileys hanya mengeluarkan
   QR kalau `creds.me` kosong. Kode `405` tidak ada di `DisconnectReason`,
   jadi dulu tidak terdeteksi sebagai logout → login terus ke sesi mati,
   QR tidak pernah muncul. Sesi lama di-*rename* ke `auth.bad.<ts>`
   (disisakan 3 backup), bukan dihapus.
4. **Teardown socket lama** (`removeAllListeners` + `end`) dan generation
   counter, supaya event socket zombie tidak memicu reconnect berlipat.

Log ditulis `sync: true`. Sebelumnya buffer async membuat error terakhir
sebelum service nyangkut ikut hilang, sehingga penyebabnya tidak terlacak.
