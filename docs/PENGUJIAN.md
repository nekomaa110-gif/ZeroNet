# Bukti pengujian

Dokumen ini mencatat apa yang sudah diuji dari README, di lingkungan mana, dan apa yang belum. Semua pengujian memakai data contoh fiktif, termasuk screenshot di README.

## Lingkungan

Langkah [Instalasi lokal](../README.md#instalasi-lokal) dijalankan dari clone bersih di dua lingkungan.

| | Lingkungan A, 3 Oktober 2026 | Lingkungan B, 4 Oktober 2026 |
|---|---|---|
| OS | Ubuntu 24.04, container | Ubuntu 22.04, folder, instance MariaDB, dan port sendiri |
| PHP | 8.3.6 | 8.3.30 |
| Composer | 2.8.12 | 2.9.7 |
| Node.js / npm | 22.22.0 / 10.9.4 | 20.20.2 / 10.8.2 |
| MariaDB | 10.11.14 | 10.6.23 |
| Cache, queue, sesi | driver `database` | driver `database` |

## Hasil langkah README (lingkungan B)

| Langkah | Hasil |
|---|---|
| 1. `git clone`, `composer install`, `npm ci`, `npm run build` | OK. Vite membuat `public/build`. |
| 2. Buat database dan user | OK. Satu-satunya perbedaan dari README: MariaDB uji memakai port 33061, diisi lewat `DB_PORT`. |
| 3. `cp .env.example .env`, `php artisan key:generate` | OK |
| 4. Isi `DB_PASSWORD` dan `ADMIN_SEED_*` | OK. `APP_DOMAIN=127.0.0.1` dan `DB_CONNECTION=mysql` sudah benar dari `.env.example`. |
| 5. `migrate`, `db:seed`, `storage:link` | OK. Dump schema dimuat, lalu 4 migration baru. Akun admin dibuat; akun operator dilewati karena `OPERATOR_SEED_*` kosong. |
| 6. `php artisan serve --host=127.0.0.1 --port=8000` | OK |
| 7. Buka halaman | `/`, `/paket`, `/coverage`, `/bantuan`, `/status`, `/kebijakan-privasi`, `/login`, `/admin/login`, `/up` menjawab 200. `/admin/dashboard` tanpa login dialihkan ke `/admin/login`. Host `localhost` mendapat 404 karena route terikat `APP_DOMAIN`. |
| Login admin | Berhasil, lalu diarahkan ke setup 2FA. |
| `php artisan schedule:list` | 9 jadwal terdaftar. |
| `php artisan queue:work --once --stop-when-empty` | Selesai tanpa galat. |
| [Tes](../README.md#tes) | `OK (158 tests, 1182 assertions)` |
| `storage/logs/laravel.log` | Kosong setelah pengujian. |

## Hasil tambahan (lingkungan A)

- Browser otomatis (Playwright): cek status akun di `/status`, login portal pelanggan, unggah bukti transfer, login admin sampai setup 2FA selesai, 21 halaman panel admin menjawab 200, dan operator ditolak di menu khusus admin.
- `DB_CONNECTION=mariadb` membuat `migrate` gagal di tabel `invoice`. Karena itu README mewajibkan `mysql`.
- Queue worker dan poller berjalan di PM2 7.0.4. `router:rutin` dan `mikrotik:poll` selesai tanpa galat saat belum ada router.
- Gateway WA (`deploy/wa-gateway`) menyala, menolak request tanpa key atau dengan key salah, dan panel membaca statusnya.

## Tes otomatis

158 tes jalan terhadap database MariaDB `*_test`. Tes yang membutuhkan router memakai router tiruan (`tests/Support/router-palsu.php`), jadi tes tidak membuktikan kompatibilitas dengan RouterOS tertentu.

## Belum diuji

| Bagian | Alasan |
|---|---|
| Router MikroTik nyata: push voucher, backup, reboot, load balance | Tidak ada router di lingkungan uji. |
| FreeRADIUS dan login hotspot | Konfigurasi FreeRADIUS tidak ada di repo. |
| Menu VPN L2TP | Skrip sinkron dan aturan sudoers tidak ada di repo. |
| Pairing WhatsApp dan pengiriman pesan | Lingkungan uji tidak tersambung ke WhatsApp. |
| Render gambar tagihan (`/send-image-url`) | Chromium untuk Puppeteer tidak diunduh. |
| WhatsApp Cloud API | Butuh akun Meta. |
| Web server, HTTPS, cron sebagai `www-data` | Hanya `php artisan serve` dan perintah manual. |
| Email dari `wa:watchdog` | `MAIL_MAILER=log`. |
| MySQL | Dump schema dibuat dengan MariaDB. |
