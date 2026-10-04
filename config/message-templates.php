<?php

return [

    'groups' => [
        'pelanggan' => [
            'label' => 'Pesan Otomatis ke Pelanggan',
            'hint'  => 'Dikirim otomatis oleh sistem (pengingat H-1, tagihan, dan konfirmasi pembayaran).',
        ],
        'admin' => [
            'label' => 'Notifikasi ke Admin',
            'hint'  => 'Masuk ke nomor bisnis, bukan ke pelanggan.',
        ],
        'broadcast' => [
            'label' => 'Template Broadcast Manual',
            'hint'  => 'Muncul sebagai pilihan cepat di halaman WhatsApp Gateway.',
        ],
    ],

    'global_placeholders' => [
        'portal_login' => 'Alamat login portal, contoh panel.example.com/login',
        'admin_wa'     => 'Nomor WhatsApp admin, contoh 6281234567890',
    ],

    'items' => [

        'reminder_invoice' => [
            'group'        => 'pelanggan',
            'label'        => 'Pengingat + Tagihan (keterangan struk)',
            'description'  => 'Dikirim H-1 bersama gambar struk invois, untuk pelanggan yang harganya sudah diketahui.',
            'placeholders' => ['name', 'username', 'expiry', 'amount', 'due_date', 'invoice_id'],
            'default'      => "⏰ *Pengingat Perpanjangan*\n\n"
                            . "Halo {name}, akun WiFi kamu (*{username}*) akan berakhir pada *{expiry}*.\n\n"
                            . "Tagihan perpanjangan: *{amount}*\n"
                            . "Jatuh tempo: {due_date}\n\n"
                            . "Rekening transfer & upload bukti ada di portal:\n"
                            . "🔗 {portal_login}\n"
                            . "_(pakai username & password voucher)_\n\n"
                            . "Butuh bantuan? Chat admin di wa.me/{admin_wa}\n\n"
                            . "Terima kasih sudah menggunakan layanan ZeroNet 🙏",
        ],

        'reminder_invoice_teks' => [
            'group'        => 'pelanggan',
            'label'        => 'Pengingat + Tagihan (cadangan teks)',
            'description'  => 'Dipakai kalau gambar struk gagal dibuat, supaya pelanggan tetap dapat kabar.',
            'placeholders' => ['name', 'username', 'expiry', 'amount', 'due_date', 'invoice_id', 'invoice_url'],
            'default'      => "⏰ *Pengingat Perpanjangan*\n\n"
                            . "Halo {name}, akun WiFi kamu (*{username}*) akan berakhir pada *{expiry}*.\n\n"
                            . "Invois: *#{invoice_id}*\n"
                            . "Total: *{amount}*\n"
                            . "Jatuh tempo: {due_date}\n\n"
                            . "Rincian & rekening transfer:\n"
                            . "🔗 {invoice_url}\n\n"
                            . "Upload bukti transfer setelah login di {portal_login} _(pakai username & password voucher)_.\n\n"
                            . "Terima kasih sudah menggunakan layanan ZeroNet 🙏",
        ],

        'reminder_polos' => [
            'group'        => 'pelanggan',
            'label'        => 'Pengingat tanpa Tagihan',
            'description'  => 'Untuk pelanggan yang belum punya riwayat pembayaran, jadi nominalnya belum diketahui.',
            'placeholders' => ['name', 'username', 'expiry'],
            'default'      => "⏰ *Pengingat Perpanjangan*\n\n"
                            . "Halo {name},\n\n"
                            . "Akun WiFi kamu (*{username}*) akan berakhir pada *{expiry}*.\n\n"
                            . "Untuk perpanjangan, silakan login ke portal pelanggan:\n"
                            . "🔗 {portal_login}\n\n"
                            . "Gunakan username & password voucher untuk masuk, lalu klik *Perpanjang Sekarang* di halaman utama.\n\n"
                            . "Butuh bantuan? Chat admin di wa.me/{admin_wa}\n\n"
                            . "Terima kasih sudah menggunakan layanan ZeroNet 🙏",
        ],

        'invoice_terbit' => [
            'group'        => 'pelanggan',
            'label'        => 'Tagihan Terbit (keterangan struk)',
            'description'  => 'Saat admin menerbitkan invois manual dari panel Billing.',
            'placeholders' => ['name', 'username', 'amount', 'due_date', 'invoice_id'],
            'default'      => "🧾 *Tagihan Perpanjangan*\n\n"
                            . "Halo {name}, ini invois kamu sebesar *{amount}*.\n\n"
                            . "Untuk info rekening transfer & upload bukti, login ke portal pelanggan:\n"
                            . "🔗 {portal_login}\n"
                            . "_(pakai username & password voucher)_\n\n"
                            . "Terima kasih 🙏",
        ],

        'invoice_terbit_teks' => [
            'group'        => 'pelanggan',
            'label'        => 'Tagihan Terbit (cadangan teks)',
            'description'  => 'Dipakai kalau gambar struk gagal dibuat.',
            'placeholders' => ['name', 'username', 'amount', 'due_date', 'invoice_id', 'invoice_url'],
            'default'      => "🧾 *Tagihan Perpanjangan*\n\n"
                            . "Halo {name}, ini invois kamu:\n\n"
                            . "Invois: *#{invoice_id}*\n"
                            . "Total: *{amount}*\n"
                            . "Jatuh tempo: {due_date}\n\n"
                            . "Rincian & rekening transfer:\n"
                            . "🔗 {invoice_url}\n\n"
                            . "Upload bukti transfer setelah login di {portal_login} _(pakai username & password voucher)_.\n\n"
                            . "Terima kasih 🙏",
        ],

        'pembayaran_diterima' => [
            'group'        => 'pelanggan',
            'label'        => 'Pembayaran Diterima (keterangan resi)',
            'description'  => 'Setelah admin mengonfirmasi pembayaran; dikirim bersama gambar resi.',
            'placeholders' => ['name', 'username', 'amount', 'active_until', 'invoice_id'],
            'default'      => "✅ *Pembayaran Diterima*\n\n"
                            . "Halo {name}, terima kasih 🙏\n"
                            . "Akun WiFi kamu sudah aktif kembali sampai *{active_until}*.\n\n"
                            . "💡 Simpan gambar ini sebagai resi pembayaran.\n"
                            . "Riwayat invois lengkap di portal: {portal_login}",
        ],

        'pembayaran_diterima_teks' => [
            'group'        => 'pelanggan',
            'label'        => 'Pembayaran Diterima (cadangan teks)',
            'description'  => 'Dipakai kalau gambar resi gagal dibuat.',
            'placeholders' => ['name', 'username', 'amount', 'active_until', 'invoice_id', 'invoice_url'],
            'default'      => "✅ *Pembayaran Diterima*\n\n"
                            . "Halo {name}, terima kasih 🙏\n"
                            . "Akun WiFi kamu sudah aktif kembali sampai *{active_until}*.\n\n"
                            . "Resi pembayaran (invois #{invoice_id}):\n"
                            . "🔗 {invoice_url}\n\n"
                            . "Riwayat invois lengkap di portal: {portal_login}",
        ],

        'perpanjangan_manual' => [
            'group'        => 'pelanggan',
            'label'        => 'Akun Diperpanjang Admin',
            'description'  => 'Dikirim saat admin menekan tombol Perpanjang di halaman User Hotspot, '
                            . 'mengabari pelanggan bahwa masa aktifnya sudah ditambah.',
            'placeholders' => ['name', 'username', 'paket', 'days', 'active_until', 'expiry_lama'],
            'default'      => "✅ *Akun Diperpanjang*\n\n"
                            . "Halo {name}, akun WiFi kamu (*{username}*) sudah diperpanjang *{days} hari*.\n\n"
                            . "Aktif sampai: *{active_until}*\n\n"
                            . "Cek status akun kapan saja di portal:\n"
                            . "🔗 {portal_login}\n"
                            . "_(pakai username & password voucher)_\n\n"
                            . "Terima kasih sudah menggunakan layanan ZeroNet 🙏",
        ],

        'admin_permintaan_perpanjangan' => [
            'group'        => 'admin',
            'label'        => 'Pelanggan Minta Perpanjangan',
            'description'  => 'Saat pelanggan menekan tombol Perpanjang Sekarang di portal.',
            'placeholders' => ['username', 'waktu', 'panel_url'],
            'default'      => "🔔 *Permintaan Perpanjangan*\n\n"
                            . "Username: *{username}*\n"
                            . "Waktu: {waktu}\n\n"
                            . "Buatkan invoice di panel admin:\n"
                            . "{panel_url}/admin/billing/create",
        ],

        'admin_bukti_transfer' => [
            'group'        => 'admin',
            'label'        => 'Bukti Transfer Masuk',
            'description'  => 'Saat pelanggan mengunggah bukti pembayaran.',
            'placeholders' => ['username', 'invoice_id', 'amount', 'bank', 'waktu', 'panel_url'],
            'default'      => "💸 *Bukti Pembayaran Diterima*\n\n"
                            . "Invoice: #{invoice_id}\n"
                            . "Username: *{username}*\n"
                            . "Total: {amount}\n"
                            . "Transfer ke: {bank}\n"
                            . "Waktu upload: {waktu}\n\n"
                            . "Lihat bukti & konfirmasi:\n"
                            . "{panel_url}/admin/billing/{invoice_id}",
        ],

        'admin_ringkasan_reminder' => [
            'group'        => 'admin',
            'label'        => 'Ringkasan Reminder Expired',
            'description'  => 'Sekali tiap kali jadwal reminder jalan dan ada yang terkirim. '
                            . 'Menggantikan notifikasi "Invoice Perlu Dibuat Manual" yang dulu terpisah, '
                            . 'daftarnya sekarang ikut di dalam pesan ini lewat {blok_manual}.',
            'placeholders' => ['total', 'dengan_tagihan', 'tanpa_tagihan', 'daftar', 'blok_manual', 'waktu', 'panel_url'],
            'default'      => "🔔 *Reminder Expired Terkirim*\n\n"
                            . "Waktu: {waktu}\n"
                            . "Total: *{total}* pelanggan, {dengan_tagihan} dengan tagihan, {tanpa_tagihan} tanpa nominal\n\n"
                            . "{daftar}"
                            . "{blok_manual}\n"
                            . "Pantau di:\n"
                            . "{panel_url}/admin/billing",
        ],

        'admin_ringkasan_harian' => [
            'group'        => 'admin',
            'label'        => 'Ringkasan Harian',
            'description'  => 'Sekali sehari pagi: kondisi pelanggan, tagihan, pemasukan, dan penjualan voucher kemarin. '
                            . 'Selalu dikirim walau semua nol, jadi kalau pesannya tidak datang berarti '
                            . 'gateway atau penjadwal sedang bermasalah.',
            'placeholders' => [
                'tanggal', 'user_aktif', 'user_total', 'user_expired', 'user_disabled', 'sesi_online',
                'habis_hari_ini', 'habis_besok',
                'invoice_pending', 'invoice_draft', 'invoice_belum_bayar', 'nilai_belum_bayar', 'invoice_nunggak',
                'bayar_kemarin', 'nilai_bayar_kemarin',
                'voucher_kemarin', 'omzet_voucher_kemarin', 'voucher_per_router', 'panel_url',
            ],
            'default'      => "📊 *Ringkasan Harian ZeroNet*\n"
                            . "{tanggal}\n\n"
                            . "👥 *Pelanggan*\n"
                            . "Aktif: *{user_aktif}* dari {user_total}\n"
                            . "Expired: {user_expired} • Nonaktif: {user_disabled}\n"
                            . "Sedang online: {sesi_online}\n\n"
                            . "⏳ *Masa Aktif*\n"
                            . "Habis hari ini: {habis_hari_ini}\n"
                            . "Habis besok: {habis_besok}\n\n"
                            . "🧾 *Tagihan*\n"
                            . "Menunggu konfirmasi: *{invoice_pending}*\n"
                            . "Permintaan belum dibuatkan invois: {invoice_draft}\n"
                            . "Belum dibayar: {invoice_belum_bayar} ({nilai_belum_bayar})\n"
                            . "Lewat jatuh tempo: {invoice_nunggak}\n\n"
                            . "💰 *Masuk Kemarin*\n"
                            . "{bayar_kemarin} pembayaran (*{nilai_bayar_kemarin}*)\n\n"
                            . "🎟️ *Voucher Kemarin*\n"
                            . "{voucher_kemarin} terjual (*{omzet_voucher_kemarin}*)\n"
                            . "{voucher_per_router}\n\n"
                            . "Panel: {panel_url}/admin",
        ],

        'broadcast_portal' => [
            'group'        => 'broadcast',
            'label'        => 'Pengumuman Portal Baru',
            'description'  => 'Tombol cepat di halaman WhatsApp Gateway.',
            'placeholders' => ['name', 'username'],
            'default'      => "Halo {name} 👋\n\n"
                            . "Kabar baru dari ZeroNet 🎉\n\n"
                            . "Sekarang kamu bisa cek status akun & perpanjang paket bulanan langsung dari HP, tanpa perlu chat admin satu-satu:\n\n"
                            . "🔗 {portal_login}\n\n"
                            . "Login pakai *username & password voucher* kamu, di sana tersedia:\n"
                            . "✅ Status akun & tanggal berakhir\n"
                            . "✅ Perpanjang paket online\n"
                            . "✅ Riwayat tagihan & resi pembayaran\n\n"
                            . "Reminder otomatis 1 hari sebelum akun expired juga sudah aktif. Tidak khawatir lupa lagi 😊\n\n"
                            . "Terima kasih sudah jadi pelanggan setia ZeroNet 🙏",
        ],

        'broadcast_reminder' => [
            'group'        => 'broadcast',
            'label'        => 'Pengingat Manual',
            'description'  => 'Tombol cepat di halaman WhatsApp Gateway.',
            'placeholders' => ['name', 'username'],
            'default'      => "Halo {name} 👋\n\n"
                            . "Ini pengingat ramah dari ZeroNet, akun WiFi *{username}* kamu akan habis dalam waktu dekat.\n\n"
                            . "Untuk perpanjang, login ke:\n"
                            . "🔗 {portal_login}\n\n"
                            . "(pakai username & password voucher kamu)\n\n"
                            . "Terima kasih 🙏",
        ],

        'broadcast_gangguan' => [
            'group'        => 'broadcast',
            'label'        => 'Info Gangguan',
            'description'  => 'Tombol cepat di halaman WhatsApp Gateway.',
            'placeholders' => ['name', 'username'],
            'default'      => "Halo {name} 🙏\n\n"
                            . "Mohon maaf, saat ini sedang ada gangguan jaringan di area kamu. Tim kami sedang menangani.\n\n"
                            . "Kami akan kabari lagi setelah pulih.\n\n"
                            . "Terima kasih atas kesabarannya.\n\n"
                            . "_ZeroNet_",
        ],
    ],

    'placeholder_meta' => [
        'name'         => ['Nama panggilan pelanggan (jatuh ke username kalau kosong)', 'Budi'],
        'username'     => ['Username RADIUS pelanggan', 'admin'],
        'expiry'       => ['Kapan akun berakhir', '08 Aug 2026 pukul 23:59'],
        'amount'       => ['Nominal tagihan', 'Rp 200.000'],
        'due_date'     => ['Jatuh tempo invois', '08 Aug 2026'],
        'invoice_id'   => ['Nomor invois', '62'],
        'invoice_url'  => ['Tautan struk invois', 'https://' . env('APP_DOMAIN', 'localhost') . '/i/INV-contoh'],
        'active_until' => ['Akun aktif sampai kapan setelah dibayar', '06 Sep 2026'],
        'days'         => ['Berapa hari masa aktif ditambah', '30'],
        'paket'        => ['Nama paket/profil pelanggan', 'Member'],
        'expiry_lama'  => ['Tanggal berakhir sebelum diperpanjang', '25 Sep 2026'],
        'bank'         => ['Rekening tujuan transfer', 'BRI'],
        'waktu'        => ['Waktu kejadian', '07 Aug 2026 23:45'],
        'daftar'       => ['Daftar pelanggan yang dapat reminder', "• *budi01* (habis 08 Aug 2026) • Rp 200.000 (inv #62)\n"],
        'panel_url'    => ['Alamat panel admin', 'https://' . env('APP_DOMAIN', 'localhost')],
        'portal_login' => ['Alamat login portal', env('APP_DOMAIN', 'localhost') . '/login'],
        'admin_wa'     => ['Nomor WhatsApp admin', '6281234567890'],

        'total'          => ['Banyaknya reminder terkirim', '3'],
        'dengan_tagihan' => ['Reminder yang menyertakan nominal', '2'],
        'tanpa_tagihan'  => ['Reminder tanpa nominal (harga belum ketahuan)', '1'],
        'blok_manual'    => [
            'Blok daftar pelanggan yang perlu invoice manual (otomatis kosong kalau tidak ada)',
            "\n⚠️ *Perlu invoice manual* (belum ada riwayat lunas):\n• *budi01* (habis 08 Aug 2026)\n",
        ],

        'tanggal'             => ['Tanggal ringkasan', 'Minggu, 09 Aug 2026'],
        'user_aktif'          => ['Pelanggan yang masih aktif', '48'],
        'user_total'          => ['Seluruh pelanggan terdaftar', '57'],
        'user_expired'        => ['Pelanggan yang masa aktifnya habis', '7'],
        'user_disabled'       => ['Pelanggan yang dinonaktifkan', '2'],
        'sesi_online'         => ['Sesi yang sedang online saat ringkasan dibuat', '31'],
        'habis_hari_ini'      => ['Pelanggan yang masa aktifnya habis hari ini', '2'],
        'habis_besok'         => ['Pelanggan yang masa aktifnya habis besok', '4'],
        'invoice_pending'     => ['Invois menunggu konfirmasi pembayaran', '1'],
        'invoice_draft'       => ['Permintaan perpanjangan yang belum dibuatkan invois', '2'],
        'invoice_belum_bayar' => ['Invois terbit tapi belum dibayar', '5'],
        'nilai_belum_bayar'   => ['Total nilai invois belum dibayar', 'Rp 1.000.000'],
        'invoice_nunggak'     => ['Invois yang sudah lewat jatuh tempo', '2'],
        'bayar_kemarin'       => ['Pembayaran yang dikonfirmasi kemarin', '3'],
        'nilai_bayar_kemarin' => ['Total nilai pembayaran kemarin', 'Rp 600.000'],
    ],
];
