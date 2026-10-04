# Kebijakan keamanan

## Melaporkan celah keamanan

Jangan laporkan celah keamanan lewat issue publik.

Gunakan tombol **Report a vulnerability** di tab **Security** repo ini. Laporan lewat jalur itu hanya terlihat oleh pemilik repo. Sertakan:

- commit atau tanggal kode yang diuji,
- langkah untuk mereproduksi,
- dampak yang Anda temukan.

Jangan menguji celah pada instalasi milik orang lain tanpa izin pemiliknya.

## Cakupan

Termasuk:

- aplikasi Laravel di repo ini,
- gateway WhatsApp di `deploy/wa-gateway`.

Tidak termasuk: konfigurasi server, FreeRADIUS, router MikroTik, dan layanan pihak ketiga yang dipasang pengguna.

## Versi yang didukung

Perbaikan keamanan hanya dibuat untuk cabang `master` terbaru.
