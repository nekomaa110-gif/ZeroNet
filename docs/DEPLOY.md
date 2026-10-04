# Pemasangan di server produksi

Panduan ini melengkapi [Instalasi lokal](../README.md#instalasi-lokal). Contoh memakai Ubuntu/Debian, user web `www-data`, dan direktori `/var/www/zeronet`. Sesuaikan dengan server Anda.

> **Status uji:** panduan produksi ini **belum** diuji utuh. Yang sudah diuji di lingkungan terisolasi hanya instalasi versi pengembangan (`composer install` dengan dependency dev, user root), `migrate`, seeder, queue worker dan poller di PM2, serta gateway WA yang dijalankan langsung dengan `node server.js`. Mode `--no-dev`, user `www-data`, web server, HTTPS, cron, PM2 untuk gateway, FreeRADIUS, dan L2TP belum diuji. Lihat [PENGUJIAN.md](PENGUJIAN.md).

## 1. Paket sistem

Butuh PHP 8.3+ dengan ekstensi di [README](../README.md#teknologi-dan-persyaratan), Composer 2, Node.js `^20.19` atau `>=22.12`, MariaDB server dan klien CLI, web server (Apache atau Nginx + PHP-FPM), dan PM2. Redis opsional.

## 2. Kode dan dependency

```bash
sudo mkdir -p /var/www/zeronet && sudo chown www-data:www-data /var/www/zeronet
sudo -u www-data git clone https://github.com/nekomaa110-gif/ZeroNet.git /var/www/zeronet
cd /var/www/zeronet
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci
sudo -u www-data npm run build
```

## 3. `.env` produksi

```bash
sudo -u www-data cp .env.example .env
sudo -u www-data php artisan key:generate
sudo chmod 640 .env
```

Ubah minimal:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.example.com
APP_DOMAIN=panel.example.com
LOG_LEVEL=warning

DB_PASSWORD=...
BUSINESS_WA=62...
ADMIN_NOTIF_WA=62...
BILLING_REKENING="Bank ...|nomor|atas nama"
VOUCHER_LOGIN_URL=...
```

Variabel lain ada di [KONFIGURASI.md](KONFIGURASI.md).

## 4. Database dan akun admin

```bash
sudo -u www-data php artisan migrate --force
```

Untuk membuat akun admin pertama di produksi, isi `ADMIN_SEED_*` dan set `ADMIN_SEED_ALLOW_PRODUCTION=true`, lalu:

```bash
sudo -u www-data php artisan db:seed --force
```

Setelah itu kembalikan `ADMIN_SEED_ALLOW_PRODUCTION=false` dan hapus nilai `ADMIN_SEED_PASSWORD` dari `.env`.

## 5. Storage dan izin

```bash
sudo -u www-data php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
```

`public/robots.txt` dan `public/sitemap.xml` tidak ikut di repo karena isinya bergantung pada domain. Buat dari contoh lalu ganti domainnya:

```bash
sudo -u www-data cp public/robots.txt.example public/robots.txt
sudo -u www-data cp public/sitemap.xml.example public/sitemap.xml
sudo -u www-data sed -i 's#panel.example.com#panel.domain-anda.com#g' public/robots.txt public/sitemap.xml
```

## 6. Web server

- Arahkan document root ke `/var/www/zeronet/public`.
- Pasang HTTPS. Gateway WA membuka `https://{APP_DOMAIN}/i/{slug}?image=1` untuk merender gambar tagihan.
- Middleware `SecurityHeaders` mengirim HSTS hanya untuk request HTTPS.
- Aplikasi tidak mempercayai reverse proxy (`trustProxies(at: [])` di `bootstrap/app.php`). Di belakang proxy, IP yang tercatat di log dan batas percobaan login adalah IP proxy.

## 7. Cache konfigurasi

Jalankan sebagai user web supaya file cache dimiliki user yang benar. `APP_DOMAIN` dan `BILLING_DOMAIN` ikut tersimpan di route cache, jadi ulangi `route:cache` setiap kali keduanya berubah:

```bash
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```

Jangan menjalankan `config:clear` atau `route:clear` sebagai user lain.

## 8. Scheduler

Tambahkan ke crontab `www-data` (`sudo crontab -u www-data -e`):

```cron
* * * * * cd /var/www/zeronet && php artisan schedule:run >> /dev/null 2>&1
```

## Proses latar belakang

Queue worker dan poller harus selalu hidup. Repo belum menyertakan file PM2 untuk keduanya. Kedua perintah `pm2 start` berikut sudah diuji berjalan dengan PM2 7.0.4 (sebagai root, belum sebagai `www-data`). Pastikan `/var/www/.pm2` bisa ditulis `www-data`:

```bash
cd /var/www/zeronet
sudo -u www-data PM2_HOME=/var/www/.pm2 pm2 start "php artisan queue:work --sleep=3" --name laravel-queue
sudo -u www-data PM2_HOME=/var/www/.pm2 pm2 start "php artisan mikrotik:poll" --name mikrotik-poller
sudo -u www-data PM2_HOME=/var/www/.pm2 pm2 save
```

Poller keluar sendiri tiap jam; PM2 menyalakannya ulang. Agar PM2 hidup kembali setelah server reboot, jalankan `pm2 startup` sesuai petunjuk PM2 untuk user `www-data`.

PM2 milik `www-data` tidak tampil di `pm2 list` user lain. Selalu pakai awalan `sudo -u www-data PM2_HOME=/var/www/.pm2`.

## Gateway WhatsApp

Ikuti bagian "Pasang dari nol" di [deploy/wa-gateway/README.md](../deploy/wa-gateway/README.md). Ringkasnya:

1. Salin isi `deploy/wa-gateway` ke `/opt/wa-gateway`, salin `.env.example` menjadi `.env`, lalu isi `API_KEY` (`openssl rand -hex 32`).
2. Pasang dependency dengan cache Puppeteer di folder gateway, lalu jalankan lewat PM2 milik `www-data`:

   ```bash
   cd /opt/wa-gateway
   sudo -u www-data PUPPETEER_CACHE_DIR=/opt/wa-gateway/.puppeteer-cache npm ci
   sudo -u www-data PM2_HOME=/var/www/.pm2 pm2 start ecosystem.config.js
   ```

   `ecosystem.config.js` mengarahkan Puppeteer ke folder cache yang sama. Tanpa Chromium di sana, render gambar tagihan gagal dan pesan dikirim sebagai teks.
3. Isi `WA_GATEWAY_KEY` di `.env` panel dengan nilai yang sama, jalankan `config:cache`.
4. Scan QR dari **WhatsApp › WhatsApp Gateway** → tombol **Panel gateway**.

## FreeRADIUS

Konfigurasi FreeRADIUS tidak ada di repo. Yang dibutuhkan panel:

- Modul `sql` FreeRADIUS memakai database yang sama dengan panel.
- Router MikroTik terdaftar sebagai client RADIUS dan profil hotspot memakai RADIUS.
- FreeRADIUS menangani atribut `Expiration` dan `Simultaneous-Use`.

Tabel `radcheck` di dump memiliki indeks unik `(username, attribute)`, dan `radacct` serta `radpostauth` punya kolom `class`. Bandingkan dengan skema FreeRADIUS versi Anda sebelum memakai database yang sudah ada.

Setelah mengubah konfigurasi FreeRADIUS, pakai `systemctl restart freeradius`, bukan `reload`.

## L2TP

Menu VPN L2TP butuh:

1. Skrip `/usr/local/sbin/l2tp-sync-secrets` yang membaca baris `username server secret remote_ip` dari STDIN dan menulis ulang `/etc/ppp/chap-secrets`. Detailnya di [ARSITEKTUR.md](ARSITEKTUR.md#l2tp).
2. Aturan sudoers agar `www-data` bisa menjalankan skrip itu tanpa password.
3. `proc_open` tidak dimatikan di PHP.
4. `L2TP_LOCAL_IP` dan `L2TP_RESERVED` di `.env` yang sesuai jaringan Anda.

Skrip dan aturan sudoers tidak disertakan dan belum diuji.

## Memperbarui server

1. Backup database.
2. `git pull`, lalu `composer install --no-dev --optimize-autoloader` kalau `composer.lock` berubah, dan `npm ci && npm run build` kalau aset berubah.
3. `php artisan migrate --force` kalau ada migration baru.
4. Ulangi langkah [cache konfigurasi](#7-cache-konfigurasi) sebagai `www-data`.
5. Restart proses yang memuat kode lama:
   - `laravel-queue` setelah mengubah `app/Jobs`, `app/Services`, atau config.
   - `mikrotik-poller` setelah mengubah `MikrotikClient`, `RouterGateway`, `MikrotikService`, atau service router lain.
6. Pastikan scheduler jalan: `sudo -u www-data php artisan schedule:list`.

## Aturan operasional

- Ubah satu router produksi dulu (script, firewall, service API, IPsec), di luar jam ramai, setelah jalur pemulihannya dicek.
- Menonaktifkan atau menghapus akun L2TP milik router memutus tunnel router itu saat router menyambung ulang. Lihat [ARSITEKTUR.md](ARSITEKTUR.md#l2tp).
- Batasi service API router (`/ip service set api address=...`) ke alamat server panel atau jaringan VPN.
- Jangan menjalankan tes dari direktori produksi. Lihat [Tes](../README.md#tes).
