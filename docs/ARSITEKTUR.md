# Arsitektur ZeroNet

Dokumen ini menjelaskan cara kerja bagian-bagian panel. Ringkasan dan diagram ada di [README](../README.md#arsitektur).

## Komponen

| Komponen | Lokasi di repo | Tugas |
|---|---|---|
| Aplikasi web | `app/`, `routes/`, `resources/views/` | Website publik, portal pelanggan, panel admin. |
| Queue worker | `app/Jobs/` | Kirim WhatsApp, push batch voucher ke router, impor voucher dari router. |
| Scheduler | `bootstrap/app.php` (`withSchedule`) | Tugas berkala, lihat [Jadwal](#jadwal). |
| Poller | `app/Console/Commands/MikrotikPoll.php` | Koneksi permanen ke tiap router, menulis snapshot ke cache. |
| WA gateway | `deploy/wa-gateway/` | Layanan Node.js terpisah yang tersambung ke WhatsApp. |
| Database | `database/schema/mysql-schema.sql`, `database/migrations/` | Tabel panel dan tabel FreeRADIUS dalam satu database. |

FreeRADIUS, skrip sinkron L2TP, dan web server tidak ada di repo.

## Routing dan domain

- Semua route web memakai `Route::domain(config('app.domain'))`. Request dengan host lain mendapat 404, kecuali `/up`.
- Domain dibaca saat route didaftarkan, jadi ikut tersimpan di route cache. Setelah mengubah `APP_DOMAIN` atau `BILLING_DOMAIN`, jalankan `config:cache` dan `route:cache`.
- Website publik: `/`, `/paket`, `/coverage`, `/bantuan`, `/kebijakan-privasi`, `/status`.
- Portal pelanggan: `/login` dan `/pelanggan/*` (`routes/customer.php`).
- Panel: `/admin/*` (`routes/web.php`), termasuk `/admin/login`.
- `/i/{slug}` (tagihan publik) berjalan tanpa sesi dan dibatasi 20 request per menit.
- Semua path di `BILLING_DOMAIN` dialihkan ke `APP_DOMAIN`.
- URL panel lama tanpa prefix (`/dashboard`, `/radius-users`, dan lain-lain) dialihkan ke `/admin/...`.
- `/up` adalah health check bawaan Laravel dan tidak terikat domain.

## Dua jenis akun hotspot

| | User hotspot (RADIUS) | Voucher |
|---|---|---|
| Disimpan di | Tabel `radcheck`, `radusergroup` | User lokal `/ip/hotspot/user` di router, salinan di tabel `vouchers` |
| Autentikasi | Router → FreeRADIUS → database | Router sendiri |
| Masa aktif | Atribut `Expiration` di `radcheck` | Script on-login dan scheduler `zeronet-expire` di router |
| Dibuat dari | Pelanggan › User Hotspot | Voucher › Generator Voucher |
| Bisa login portal | Ya | Tidak |

Paket RADIUS adalah grup di `radgroupreply` dan `radgroupcheck` (misalnya `Mikrotik-Rate-Limit`, `Simultaneous-Use`). Data tampilan paket (harga, masa aktif, tampil di website) ada di tabel `packages`.

## Koneksi ke router

- Klien API ada di `app/Services/MikrotikClient.php`: RouterOS API biner lewat TCP biasa, port bawaan 8728. Login mencoba metode baru (password langsung), lalu metode lama (challenge MD5) kalau router memintanya. Tidak ada dukungan TLS.
- Semua koneksi dibuat lewat `App\Services\RouterGateway`:
  - Satu koneksi per router per proses PHP. Tiap proses (web, worker, poller) punya koneksinya sendiri.
  - Timeout per konteks di `config/services.php` bagian `mikrotik`: web 8 detik, antrean 25 detik, poller 4 detik.
  - Router yang gagal ditandai `router.down.{slug}` di cache selama 15 detik supaya request lain tidak ikut menunggu.
  - Koneksi yang menganggur lebih dari 20 detik dibuat ulang, kecuali di poller.
- Operasi yang menulis ke router mengambil lock cache `router.tulis.{slug}`. Operasi baca dan poller tidak menunggu lock ini. TTL lock per operasi:
  - push voucher dan pulihkan script: 660 detik (`kunci_tulis_ttl`)
  - arsip penjualan: 600 detik
  - profil hotspot, pasang script, hapus user, akun panel, load balance: 120 detik
- Password router disimpan di kolom `routers.password` dengan cast `encrypted` (memakai `APP_KEY`).
- `app/Services/VoucherScriptBuilder.php` membedakan format tanggal untuk RouterOS di bawah 7.10 dan 7.10 ke atas.

## Poller dan snapshot

`php artisan mikrotik:poll` membuka koneksi permanen ke semua router di tabel `routers` dan menulis hasilnya ke cache Laravel:

| Kunci cache | Isi |
|---|---|
| `mt.poller.beat` | Detak poller. Dianggap mati kalau lebih dari 20 detik tidak diperbarui. |
| `mt.snap.{slug}` | Interface, resource, identity, route, load balance. |
| `mt.hotspot.{slug}` | Sesi hotspot aktif, host, dan cookie. Hanya diisi selama halaman Hotspot Aktif terbuka. |

Log hotspot dari router disimpan ke tabel `router_logs` (7 hari).

Cache harus dipakai bersama oleh semua proses, jadi gunakan driver `database`, `redis`, atau `file`, bukan `array`.

Kalau poller mati:

- Dashboard, statistik router, trafik live, `traffic:sample`, dan load balance membaca router langsung.
- Halaman Hotspot Aktif kosong dan `router_logs` tidak bertambah.

Poller keluar sendiri setelah `--max-time` (bawaan 3600 detik, minimal 60) atau bila memori melebihi 96 MB. Process manager harus menyalakannya lagi.

## Voucher dan penjualan gaya Mikhmon

Panel kompatibel dengan cara Mikhmon mencatat voucher di router:

- **Generator Voucher** membuat batch di tabel `voucher_batches` dan `vouchers`, lalu job `SyncVoucherBatch` mengirim user ke router per 25 perintah. Push bisa diulang tanpa menggandakan user yang sudah ada di router.
- **Script Router** memasang script on-login `zeronet-onlogin` di profil hotspot dan `/system scheduler` `zeronet-expire` (interval 3 menit). Kalau opsi `lock` di profil aktif, script mengunci MAC saat login pertama. Kalau opsi `record` aktif, script menulis catatan penjualan sebagai `/system script` dengan comment `mikhmon`.
- `voucher:penjualan` menyalin catatan itu ke tabel `voucher_sales`. Command ini mengecek jumlah catatan dengan `count-only` dulu dan membaca semua nama hanya kalau jumlahnya berubah. Identitas catatan adalah hash nama per router, jadi catatan yang terlambat atau jam router yang salah tidak terlewat.
- Harga jual (`sprice`) hanya diisi dari profil kalau catatan ditarik paling lama 60 menit setelah terjual dan harganya sama dengan harga profil saat itu. Selain itu kolomnya kosong dan panel menampilkan "belum diisi".
- `voucher:import --router=<slug> --dry` menampilkan rencana impor voucher Mikhmon yang sudah ada di router tanpa menulis apa pun. Tanpa `--dry`, voucher masuk ke batch `<comment>@<slug>` dengan sumber `mikhmon_import`. Impor bisa diulang tanpa duplikat (unik per router dan username). Dari panel tersedia lewat tombol impor di Generator Voucher.
- `voucher:arsip-penjualan --router=<slug>` menghapus dari router catatan yang lebih tua dari bulan berjalan ditambah 3 bulan sebelumnya, hanya yang salinannya sudah ada di `voucher_sales`. Tanpa `--jalan` command ini hanya melapor. Command ini **tidak dijadwalkan**.

### Pindah dari Mikhmon

Dua command membantu perpindahan per router:

- `router:simpan-script --router=<slug>` menyimpan snapshot baca-saja (on-login, scheduler, script, service, user) ke `storage/app/cutover/<slug>/` beserta file `.sha256`.
- `router:pulihkan-script --router=<slug>` menampilkan langkah untuk mengembalikan on-login dan scheduler ke snapshot. Tambahkan `--jalan` untuk benar-benar menulis ke router.

Ikuti [aturan operasional](DEPLOY.md#aturan-operasional) saat mengubah router produksi.

## Hotspot Aktif

Halaman **Jaringan › Hotspot Aktif** membaca `mt.hotspot.{slug}`. Poller mengisinya hanya selama halaman terbuka: `active` dan `host` tiap 10 detik, `cookie` tiap 30 detik. Data ini dipisah dari `mt.snap.{slug}` supaya data yang dibaca dashboard tiap beberapa detik tetap kecil.

Putus sesi dan hapus cookie hanya untuk admin. Sebelum menghapus, server mencocokkan ulang ID, user, dan MAC di router karena ID sesi bisa dipakai ulang perangkat lain. Setiap aksi tercatat di Log Aktivitas. Di RouterOS 7, putus sesi ikut menghapus mac-cookie perangkat, jadi pelanggan harus login manual.

## Reconcile radacct

`radacct:reconcile` menutup sesi di tabel `radacct` yang sudah tidak ada di daftar aktif router.

- Uji tanpa menulis: `php artisan radacct:reconcile --dry-run`.
- `RADACCT_NAS_MAP` (format `slug=ip,ip;slug=ip`) memetakan router ke NAS-IP-Address. Kalau diisi, router yang gagal dihubungi tidak membatalkan reconcile router lain, dan sesi dari NAS yang tidak dikenal tidak disentuh. Kalau kosong, semua router dibaca dan reconcile batal bila satu router gagal.
- Isi `RADACCT_NAS_MAP` hanya kalau NAS-IP-Address router tetap. Kalau IP router berubah-ubah (misalnya dari DHCP modem), biarkan kosong.

## Billing

1. Tagihan dibuat dengan salah satu cara:
   - Admin membuat tagihan untuk username yang punya `Cleartext-Password` dan belum punya tagihan terbuka. Tagihan langsung berstatus `unpaid`.
   - Pelanggan mengajukan perpanjangan dari portal. Tagihan berstatus `draft`, dan admin harus menerbitkannya.
   - `wa:reminders` membuat tagihan otomatis untuk pelanggan yang pernah lunas, hanya kalau `BILLING_WA_NOTIFICATIONS=true`.
2. Tagihan terbit bisa dibuka di `/i/{slug}`. Pelanggan menerima notifikasi WhatsApp kalau notifikasi aktif.
3. Pelanggan mengunggah bukti transfer di portal. Status menjadi `pending_confirmation` dan nomor admin mendapat notifikasi.
4. Admin mengonfirmasi. Panel memperpanjang atribut `Expiration` dan mengirim notifikasi WhatsApp kalau notifikasi aktif.

Tabel `invoice` berasal dari dump schema, bukan migration.

## WhatsApp

- `App\Services\WhatsAppService` memanggil gateway (`WA_GATEWAY_URL`) dengan header `x-api-key`.
- Pengiriman lewat job `SendWhatsAppMessage` (3 percobaan, jeda 30 detik) dan `SendWhatsAppImage` (tagihan sebagai gambar, kembali ke teks kalau gagal).
- Gateway merender gambar tagihan dari `https://{APP_DOMAIN}/i/{slug}?image=1` dengan Puppeteer.
- `BILLING_WA_NOTIFICATIONS=false` mematikan semua WA otomatis: notifikasi billing, pengingat, notifikasi admin, dan ringkasan harian.
- `BILLING_WA_TEST_MODE=true` mengalihkan pesan otomatis untuk pelanggan ke `BILLING_WA_TEST_NUMBER`.
- Kirim manual dan broadcast dari halaman WhatsApp Gateway tidak membaca kedua saklar itu dan langsung terkirim ke nomor pelanggan.
- Notifikasi admin dikirim ke daftar di **WhatsApp › Notifikasi WA**. `ADMIN_NOTIF_WA` hanya dipakai selama daftar itu kosong.
- Tombol **Cloud API** (`/admin/whatsapp/cloud`) menjalankan Embedded Signup Meta, verifikasi nomor, dan subscribe WABA, lalu menampilkan token untuk disalin ke `.env`. Belum ada kode yang mengirim pesan lewat Cloud API.

## L2TP

`App\Services\L2tpAccountService::sync()` berjalan setiap kali akun L2TP dibuat, diubah, diaktifkan, dinonaktifkan, atau dihapus. Fungsi ini:

1. Menyusun satu baris per akun aktif dengan format `username server secret remote_ip`. `server` diambil dari `config/l2tp.php` (bawaan `l2tpd`).
2. Menjalankan `sudo -n /usr/local/sbin/l2tp-sync-secrets` dan mengirim baris-baris itu lewat STDIN (timeout 15 detik).
3. Menganggap gagal kalau skrip keluar dengan kode bukan 0.

Perubahan di tabel `l2tp_accounts` disimpan sebelum skrip dipanggil, tanpa transaksi. Kalau skrip gagal, panel menampilkan galat tetapi data di tabel sudah berubah.

Skrip harus menulis ulang **seluruh** `/etc/ppp/chap-secrets` dari input. Kalau akun L2TP milik sebuah router dinonaktifkan, tunnel router itu putus saat router menyambung ulang.

## Command artisan

| Command | Dijadwalkan | Fungsi |
|---|---|---|
| `mikrotik:poll` | tidak, proses tetap | Poller, lihat [Poller dan snapshot](#poller-dan-snapshot). |
| `router:rutin` | tiap 5 menit | Menjalankan `traffic:sample`, `radacct:reconcile`, `voucher:penjualan`, dan `voucher:sinkron` pada menit kelipatan 15. |
| `traffic:sample` | lewat `router:rutin` | Simpan counter WAN ke `traffic_samples`. |
| `radacct:reconcile` | lewat `router:rutin` | Tutup sesi radacct yatim (`--dry-run`, `--routers=`). |
| `voucher:penjualan` | lewat `router:rutin`; `--penuh` 03:15 | Tarik catatan penjualan ke `voucher_sales`. |
| `voucher:sinkron` | lewat `router:rutin` | Samakan status voucher dan kirim ulang batch yang tertunda (`--dry`, `--no-push`). |
| `voucher:import` | tidak | Impor voucher Mikhmon dari router (`--dry`). |
| `voucher:arsip-penjualan` | tidak | Hapus catatan penjualan lama di router (`--jalan`). |
| `voucher:cek` | tidak | Telusuri satu voucher di router. |
| `router:simpan-script` | tidak | Snapshot script dan scheduler router. |
| `router:pulihkan-script` | tidak | Kembalikan script dari snapshot (`--jalan`). |
| `router:log-bersihkan` | 04:20 | Hapus `router_logs` lebih dari 7 hari. |
| `wa:reminders` | tiap jam | Pengingat masa aktif untuk username yang punya baris di `customer_contacts`. Kalau `BILLING_WA_NOTIFICATIONS=true`, juga membuat tagihan otomatis untuk pelanggan yang pernah lunas dan belum punya tagihan terbuka. |
| `wa:daily-summary` | 08:00 | Ringkasan harian ke admin. |
| `wa:watchdog` | tiap 5 menit | Banner merah di panel dan email ke admin kalau gateway putus lebih dari 15 menit. Email hanya terkirim kalau `MAIL_MAILER` bukan `log`/`array` dan akun admin punya email. |
| `logs:archive` | tanggal 1, 02:00 | Arsip `activity_logs` ke CSV dalam ZIP di `storage/app/log-archives`. |
| `logs:cleanup` | 04:00 | Hapus `radpostauth` lebih dari 7 hari, `activity_logs` lebih dari 30 hari, dan sesi kedaluwarsa. |
| `queue:prune-failed --hours=720` | 04:10 | Hapus failed job lebih dari 30 hari. |

## Jadwal

Jadwal didefinisikan di `bootstrap/app.php`. Lihat daftar aktif dengan:

```bash
php artisan schedule:list
```
