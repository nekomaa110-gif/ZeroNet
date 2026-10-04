<?php

$nasMap = [];

foreach (array_filter(array_map('trim', explode(';', (string) env('RADACCT_NAS_MAP', '')))) as $entry) {
    [$slug, $ips] = array_pad(array_map('trim', explode('=', $entry, 2)), 2, '');

    $ips = array_values(array_filter(array_map('trim', explode(',', $ips))));

    if ($slug !== '' && $ips) {
        $nasMap[$slug] = $ips;
    }
}

return [

    'nas_map' => $nasMap,

];
