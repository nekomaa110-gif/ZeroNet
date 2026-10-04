# Konfigurasi

Aplikasi membaca `.env` hanya lewat file di `config/`. Beberapa nilai (timeout router, L2TP) tertulis langsung di `config/` dan belum bisa diubah lewat `.env`. Setelah mengubah `.env` di server yang memakai `php artisan config:cache`, jalankan perintah itu lagi. Untuk `APP_DOMAIN` dan `BILLING_DOMAIN`, jalankan juga `php artisan route:cache`.

Kolom **Bawaan** adalah nilai yang dipakai kalau variabel tidak ada di `.env`. Bawaan sengaja netral (`localhost`, kosong, contoh), jadi data bisnis seperti domain, nomor WhatsApp, dan rekening harus diisi di `.env`.

`.env.example` hanya memuat variabel untuk instalasi awal, tanpa komentar. Variabel opsional seperti WhatsApp Cloud API, backup lewat SFTP, `SITE_TAGLINE`, `VOUCHER_NOTE`, dan `REDIS_QUEUE_RETRY_AFTER` tidak tercantum di sana. Tambahkan dari tabel di bawah kalau dibutuhkan.

## Aplikasi dan domain

| Variabel | Bawaan | Fungsi |
|---|---|---|
| `APP_NAME` | `Laravel` | Nama merek di website, panel, dan portal. `.env.example` mengisi `ZeroNet`. |
| `APP_URL` | `http://localhost` | URL dasar aplikasi. |
| `APP_DOMAIN` | `localhost` | Host yang dilayani semua route web. Tanpa skema dan port: `127.0.0.1` untuk lokal, domain panel untuk produksi. Ikut tersimpan di route cache. |
| `BILLING_DOMAIN` | kosong | Domain lama; semua path dialihkan ke `APP_DOMAIN`. Kosong berarti tidak ada pengalihan. |
| `APP_TIMEZONE` | `Asia/Jakarta` | Zona waktu aplikasi dan jadwal. |
| `APP_KEY` | kosong | Dibuat `php artisan key:generate`. Dipakai untuk mengenkripsi password router dan secret 2FA. Jangan diganti setelah data terisi; gunakan `APP_PREVIOUS_KEYS` untuk rotasi. |

## Database, cache, queue, sesi

| Variabel | Bawaan | Fungsi |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | **Harus `mysql`**, juga untuk server MariaDB. Laravel hanya memuat `database/schema/mysql-schema.sql` untuk koneksi bernama `mysql`, dan tabel FreeRADIUS serta `invoice` hanya ada di file itu. SQLite tidak didukung. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | | Koneksi database. FreeRADIUS harus memakai database yang sama. |
| `CACHE_STORE` | `database` | Harus bisa dibaca semua proses (`database`, `redis`, `file`). Poller menulis snapshot ke sini. |
| `QUEUE_CONNECTION` | `database` | `database` atau `redis`. |
| `DB_QUEUE_RETRY_AFTER` | `90` | Untuk queue `database`. Isi `660` (lebih besar dari timeout push voucher 600 detik) supaya job tidak dijalankan dua kali. |
| `REDIS_QUEUE_RETRY_AFTER` | `660` | Padanan untuk queue `redis`. Kalau worker mati di tengah job, job baru dicoba ulang setelah 11 menit. |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | `phpredis`, `127.0.0.1`, `6379` | Hanya kalau memakai Redis. `phpredis` butuh ekstensi PHP `redis`. |
| `SESSION_DRIVER`, `SESSION_LIFETIME` | `database`, `120` | Sesi admin juga keluar otomatis setelah menganggur selama `SESSION_LIFETIME` menit. |

## Akun awal

| Variabel | Fungsi |
|---|---|
| `ADMIN_SEED_NAME`, `ADMIN_SEED_USERNAME`, `ADMIN_SEED_EMAIL`, `ADMIN_SEED_PASSWORD` | Akun admin yang dibuat `php artisan db:seed`. |
| `OPERATOR_SEED_*` | Sama, untuk akun operator. |
| `ADMIN_SEED_ALLOW_PRODUCTION` | `false`. Seeder dilewati kalau `APP_ENV=production`, kecuali ini `true`. |

Seeder memakai `updateOrCreate` berdasarkan email, jadi menjalankannya lagi akan mengganti password akun dengan email yang sama. Akun dilewati kalau salah satu dari nama, username, email, atau password kosong.

## Keamanan

| Variabel | Bawaan | Fungsi |
|---|---|---|
| `TWO_FACTOR_REQUIRED_ROLES` | `admin` | Role panel yang wajib 2FA, pisahkan dengan koma. Mengosongkannya mematikan kewajiban 2FA; lakukan hanya dalam keadaan darurat. |

## Identitas bisnis dan website

| Variabel | Bawaan | Fungsi |
|---|---|---|
| `BUSINESS_WA` | kosong | Nomor WhatsApp bisnis di website, portal, dan template pesan. Format `62...`. |
| `ADMIN_NOTIF_WA` | `BUSINESS_WA` | Nomor admin untuk notifikasi, hanya dipakai selama daftar di **WhatsApp › Notifikasi WA** kosong. |
| `SITE_TAGLINE` | teks bawaan | Judul besar di beranda. |
| `SITE_STATUS_CHECK` | `true` | Aktifkan halaman `/status`. |
| `VOUCHER_BRAND` | `APP_NAME` | Merek di kartu voucher. |
| `VOUCHER_LOGIN_URL` | `hotspot.lan` | Alamat login hotspot yang dicetak di kartu voucher. |
| `SITE_EMAIL` | kosong | Email kontak di halaman kebijakan privasi. Kosong berarti hanya WhatsApp yang ditampilkan. |
| `INVOICE_BRAND` | `APP_NAME` | Merek di kepala kuitansi `/i/{slug}`. |
| `VOUCHER_NOTE` | teks bawaan | Petunjuk singkat di kartu voucher. |

FAQ, jam layanan, dan teks website lain diatur langsung di `config/site.php`. Pengaturan voucher lain (karakter kode, nama script, interval scheduler) ada di `config/voucher.php`.

## WhatsApp

| Variabel | Bawaan | Fungsi |
|---|---|---|
| `WA_GATEWAY_URL` | `http://127.0.0.1:3001` | Alamat gateway. |
| `WA_GATEWAY_KEY` | kosong | Sama persis dengan `API_KEY` di `.env` gateway, minimal 24 karakter. |
| `WA_REMINDER_HOURS_BEFORE` | `24` | Jam sebelum masa aktif habis untuk pengingat. |
| `BILLING_WA_NOTIFICATIONS` | `false` | Saklar utama semua WA otomatis: notifikasi billing, pengingat, notifikasi admin, dan ringkasan harian. |
| `BILLING_WA_TEST_MODE` | `true` | Alihkan pesan otomatis untuk pelanggan ke `BILLING_WA_TEST_NUMBER`. Kirim manual dan broadcast dari halaman WhatsApp Gateway **tidak** ikut dialihkan. |
| `BILLING_WA_TEST_NUMBER` | kosong | Nomor uji. |
| `META_APP_ID`, `META_APP_SECRET`, `META_ES_CONFIG_ID`, `META_GRAPH_VERSION` | kosong, kosong, kosong, `v23.0` | Embedded Signup WhatsApp Cloud API. |
| `WA_CLOUD_PHONE_ID`, `WA_CLOUD_WABA_ID`, `WA_CLOUD_TOKEN` | kosong | Hasil onboarding Cloud API, disalin manual. Belum dipakai untuk mengirim pesan. |

Template pesan bawaan ada di `config/message-templates.php` dan bisa diubah dari **WhatsApp › Template Pesan**.

## Rekening tujuan transfer

| Variabel | Bawaan | Fungsi |
|---|---|---|
| `BILLING_REKENING` | kosong | Rekening yang tampil di portal pelanggan, kuitansi publik, dan form tagihan admin. |

Format: `bank|nomor rekening|atas nama`, beberapa rekening dipisah titik koma. Contoh:

```dotenv
BILLING_REKENING="Bank Contoh|1234567890|BUDI SANTOSO;BCA|9876543210|SARI"
```

Pilihan pelanggan disimpan di kolom `invoice.bank_to` sebagai kunci yang diturunkan dari nama bank (tanpa awalan "Bank") dan atas nama, misalnya `contoh-budi-santoso`. Jangan mengganti nama bank atau atas nama rekening yang sudah dipakai invoice lama, karena kuncinya ikut berubah. Kalau variabel ini kosong, pelanggan tidak bisa mengirim bukti transfer.

## Router MikroTik

| Variabel | Bawaan | Fungsi |
|---|---|---|
| `ROUTER_BACKUP_PASSWORD` | kosong | Kalau diisi, file `/system backup` dienkripsi dengan password ini. Simpan password ini; tanpanya backup tidak bisa dipulihkan. |
| `ROUTER_BACKUP_SFTP_HOST` | kosong | Kalau diisi, router mengunggah backup ke server lewat SFTP (`/tool fetch`). Kosong berarti panel mengambil backup lewat FTP port 21. |
| `ROUTER_BACKUP_SFTP_PORT`, `_USER`, `_PASSWORD` | `22`, kosong, kosong | Akun SFTP yang dipakai router. |
| `ROUTER_BACKUP_SFTP_REMOTE_DIR` | `incoming` | Folder tujuan di server SFTP. |
| `ROUTER_BACKUP_SFTP_LOCAL_DIR` | `/srv/router-backup/incoming` | Folder lokal tempat panel membaca hasil unggahan. |
| `RADACCT_NAS_MAP` | kosong | Peta router ke NAS-IP untuk `radacct:reconcile`. Lihat [ARSITEKTUR.md](ARSITEKTUR.md#reconcile-radacct). |

Nilai berikut ada di `config/services.php` bagian `mikrotik` dan belum bisa diubah lewat `.env`:

- Akun API panel: nama dan grup `zeronet-api`, policy `api,read,write,policy,test,reboot,sensitive`.
- Timeout: web 8 detik, antrean 25 detik, poller 4 detik.
- Penanda router mati 15 detik, koneksi ulang setelah menganggur 20 detik, lock tulis bawaan 660 detik (beberapa operasi memakai TTL sendiri, lihat [ARSITEKTUR.md](ARSITEKTUR.md#koneksi-ke-router)).

## L2TP

| Variabel | Bawaan | Fungsi |
|---|---|---|
| `L2TP_LOCAL_IP` | `10.255.255.1` | Alamat server di jaringan tunnel. Panel membagikan alamat dari oktet `pool_start` sampai `pool_end` di subnet yang sama. |
| `L2TP_RESERVED` | kosong | Oktet yang tidak boleh dibagikan, format `oktet:alasan`, dipisah titik koma. Contoh `9:pool klien IKEv2`. Alasan tampil di halaman VPN L2TP. |

Nilai lain ada di `config/l2tp.php` dan belum lewat `.env`: `pool_start` dan `pool_end` (bawaan 3 sampai 10), `server_name` (kolom server di `chap-secrets`, bawaan `l2tpd`), dan `sync_script` (path skrip sinkron yang dijalankan lewat `sudo -n`).
