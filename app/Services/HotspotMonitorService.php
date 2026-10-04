<?php

namespace App\Services;

use App\Models\Router;
use RuntimeException;

class HotspotMonitorService
{
    public const BASI_DETIK = 60;

    private const JALUR = [
        'active' => '/ip/hotspot/active',
        'cookie' => '/ip/hotspot/cookie',
    ];

    public function __construct(
        private RouterGateway $gateway,
        private RouterSnapshotService $snap,
    ) {}

    public function data(Router $router): array
    {
        $this->snap->watchHotspot($router->slug);

        $s   = $this->snap->get($router->slug);
        $h   = $this->snap->hotspot($router->slug);
        $out = [
            'poller_hidup' => $this->snap->pollerAlive(),
            'online'       => $s['online'] ?? null,
            'error'        => $s['error'] ?? null,
        ];

        foreach (['active', 'host', 'cookie'] as $bagian) {
            $p     = $h[$bagian] ?? null;
            $umur  = $p ? (int) round(microtime(true) - $p['ts']) : null;
            $out[$bagian] = [
                'data' => $p['data'] ?? null,
                'umur' => $umur,
                'basi' => $umur !== null && $umur > self::BASI_DETIK,
            ];
        }

        return $out;
    }

    public function putuskan(Router $router, string $id, string $user, string $mac): array
    {
        return $this->hapus($router, 'active', $id, $user, $mac);
    }

    public function hapusCookie(Router $router, string $id, string $user, string $mac): array
    {
        return $this->hapus($router, 'cookie', $id, $user, $mac);
    }

    private function hapus(Router $router, string $bagian, string $id, string $user, string $mac): array
    {
        $jalur = self::JALUR[$bagian];

        $baris = $this->gateway->jalankan($router->slug, $router->toConfig(), function (MikrotikClient $c) use ($jalur, $id, $user, $mac) {
            $ada = $c->query("{$jalur}/print", ['=.proplist' => '.id,user,mac-address', '?.id' => $id])[0] ?? null;

            if (! $ada) {
                throw new RuntimeException('Data itu sudah tidak ada di router (mungkin sudah logout atau kedaluwarsa). Daftar dimuat ulang.');
            }

            if (strcasecmp((string) ($ada['user'] ?? ''), $user) !== 0 || strcasecmp((string) ($ada['mac-address'] ?? ''), $mac) !== 0) {
                throw new RuntimeException('ID itu sekarang dipakai user atau perangkat lain. Daftar dimuat ulang, periksa lagi sebelum mengulang.');
            }

            $c->query("{$jalur}/remove", ['=.id' => $id]);

            return $ada;
        });

        $this->snap->buangHotspot($router->slug, $bagian, $id);

        return $baris;
    }
}
