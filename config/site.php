<?php

return [

    'brand'   => (string) env('APP_NAME', 'ZeroNet'),
    'tagline' => env('SITE_TAGLINE', 'Internet Mudah, Cepat, Tanpa Ribet'),

    'description' => 'ZeroNet menyediakan layanan internet WiFi harian, mingguan, '
        .'dan bulanan dengan harga terjangkau untuk rumah dan usaha.',

    'whatsapp'      => (string) env('BUSINESS_WA', ''),
    'email'         => (string) env('SITE_EMAIL', ''),
    'invoice_brand' => (string) env('INVOICE_BRAND', env('APP_NAME', 'ZeroNet')),
    'whatsapp_text' => 'Halo ZeroNet, saya mau tanya soal layanan internet.',

    'hours' => [
        'Senin – Jumat' => '11.00 – 21.00',
        'Sabtu'         => '11.00 – 18.00',
        'Minggu'        => 'Tutup',
    ],

    'highlights' => [
        [
            'title' => 'Pasang Cepat',
            'text'  => 'Survei dan pemasangan dijadwalkan segera setelah pendaftaran disetujui.',
        ],
        [
            'title' => 'Harga Transparan',
            'text'  => 'Biaya jelas di depan, tanpa potongan tersembunyi dan tanpa kontrak panjang.',
        ],
        [
            'title' => 'Dukungan Lokal',
            'text'  => 'Tim teknis ada di sekitar area layanan, gangguan ditangani langsung.',
        ],
        [
            'title' => 'Pilihan Fleksibel',
            'text'  => 'Tersedia paket harian, mingguan, dan bulanan sesuai kebutuhan.',
        ],
    ],

    'help_topics' => [
        'Internet Tidak Bisa Digunakan',
        'Lupa Username / Password',
        'Pembelian Voucher',
        'Perpanjangan Paket',
        'Gangguan Jaringan',
        'Keperluan Lainnya',
    ],

    'faq' => [
        [
            'q' => 'Internet saya tiba-tiba tidak bisa dipakai, apa yang harus dilakukan?',
            'a' => 'Matikan WiFi di perangkat lalu nyalakan lagi. Kalau masih bermasalah, '
                .'restart router/perangkat penerima dengan mencabut adaptor listrik sekitar 30 detik. '
                .'Bila tetap tidak bisa, hubungi admin lewat WhatsApp.',
        ],
        [
            'q' => 'Bagaimana cara memperpanjang paket?',
            'a' => 'Hubungi admin lewat WhatsApp sebelum masa aktif habis. '
                .'Pelanggan bulanan juga bisa melihat tagihan dan mengunggah bukti transfer lewat portal pelanggan.',
        ],
        [
            'q' => 'Saya lupa username atau password WiFi, bisa dibantu?',
            'a' => 'Bisa. Hubungi admin dan sebutkan nama serta alamat pemasangan untuk verifikasi, '
                .'nanti akun akan dibantu dipulihkan.',
        ],
        [
            'q' => 'Kenapa kecepatan terasa lebih lambat pada jam tertentu?',
            'a' => 'Pada jam sibuk (malam hari) jaringan dipakai bersama sehingga kecepatan bisa turun. '
                .'Kalau lambatnya terasa sepanjang hari, laporkan ke admin agar dicek.',
        ],
        [
            'q' => 'Apakah bisa dipasang di luar area layanan?',
            'a' => 'Perlu survei lokasi lebih dulu. Silakan hubungi admin dengan menyebutkan alamat lengkap.',
        ],
    ],

    'status_check_enabled' => (bool) env('SITE_STATUS_CHECK', true),

];
