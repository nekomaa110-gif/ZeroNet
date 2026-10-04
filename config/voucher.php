<?php

return [

    'max_per_batch'  => 1000,
    'default_qty'    => 100,

    'script_name'    => 'zeronet-onlogin',
    'scheduler_name' => 'zeronet-expire',

    'scheduler_interval' => '00:03:00',

    'sync_chunk'     => 25,

    'charsets' => [
        'lower'  => ['label' => 'Huruf kecil (abcd)',        'chars' => 'abcdefghijkmnprstuvwxyz'],
        'upper'  => ['label' => 'Huruf besar (ABCD)',        'chars' => 'ABCDEFGHJKLMNPRSTUVWXYZ'],
        'upplow' => ['label' => 'Huruf campur (aBcD)',       'chars' => 'ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz'],
        'num'    => ['label' => 'Angka (2345)',              'chars' => '23456789'],
        'mix'    => ['label' => 'Angka + huruf kecil (5ab2c)', 'chars' => '23456789abcdefghijkmnprstuvwxyz'],
        'mix1'   => ['label' => 'Angka + huruf besar (5AB2C)', 'chars' => '23456789ABCDEFGHJKLMNPRSTUVWXYZ'],
        'mix2'   => ['label' => 'Angka + huruf campur (5aB2c)', 'chars' => '23456789ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz'],
    ],

    'expired_modes' => [
        'ntf' => 'Nonaktifkan (limit-uptime jadi 1s), kartu tetap tercatat di router',
        'rem' => 'Hapus dari router (bersih, tapi jejak kartu ikut hilang)',
        'off' => 'Tidak ada (masa aktif tidak diawasi panel)',
    ],

    'print_templates' => [
        'v1'  => ['label' => '1 kartu / baris',  'per_row' => 1, 'width' => '100%'],
        'v4'  => ['label' => '4 kartu / baris',  'per_row' => 4, 'width' => '25%'],
        'v8'  => ['label' => '8 kartu / baris',  'per_row' => 8, 'width' => '12.5%'],
        'v10' => ['label' => '10 kartu / baris', 'per_row' => 10, 'width' => '10%'],
    ],

    'card' => [
        'brand'  => env('VOUCHER_BRAND', env('APP_NAME', 'ZeroNet')),
        'login'  => env('VOUCHER_LOGIN_URL', 'hotspot.lan'),
        'note'   => env('VOUCHER_NOTE', 'Sambungkan ke WiFi, lalu masukkan kode di halaman login.'),
    ],
];
