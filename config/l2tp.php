<?php

return [

    'local_ip' => (string) env('L2TP_LOCAL_IP', '10.255.255.1'),

    'pool_start' => 3,
    'pool_end'   => 10,

    'reserved' => collect(explode(';', (string) env('L2TP_RESERVED', '')))
        ->map(fn (string $item) => array_map('trim', explode(':', $item, 2)))
        ->filter(fn (array $f) => ctype_digit($f[0]) && (int) $f[0] >= 1 && (int) $f[0] <= 254)
        ->mapWithKeys(fn (array $f) => [(int) $f[0] => ($f[1] ?? '') !== '' ? $f[1] : 'dicadangkan'])
        ->all(),

    'server_name' => 'l2tpd',

    'sync_script' => '/usr/local/sbin/l2tp-sync-secrets',
];
