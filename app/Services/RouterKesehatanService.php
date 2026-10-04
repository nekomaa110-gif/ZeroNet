<?php

namespace App\Services;

use App\Models\Router;
use App\Models\VoucherSale;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RouterKesehatanService
{
    public const CACHE_DETIK = 300;

    public const DISK_KRITIS = 10;

    public function __construct(
        private VoucherRouterService $routers,
        private RouterScriptSnapshot $snapshot,
    ) {}

    public function script(Router $router, bool $segar = false): array
    {
        $kunci = "router.kesehatan.{$router->slug}";

        if ($segar) {
            Cache::forget($kunci);
        }

        return Cache::remember($kunci, self::CACHE_DETIK, function () use ($router) {
            try {
                $s = $this->routers->scriptStatus($router);
            } finally {
                $this->routers->disconnect($router);
            }

            $jualan = collect($s['profiles'])->filter(fn ($p) => $p['validity'] !== '' || $p['managed']);

            $profil = $jualan->map(fn ($p) => [
                'nama'  => $p['name'],
                'jenis' => $p['managed'] ? 'panel' : ($p['expmode'] !== '' ? 'mikhmon' : 'kosong'),
            ])->values()->all();

            $jenis = collect($profil)->pluck('jenis')->unique()->values()->all();

            return [
                'diperiksa'         => now()->toIso8601String(),
                'on_login'          => count($jenis) === 1 ? $jenis[0] : ($jenis ? 'campuran' : 'kosong'),
                'profil'            => $profil,
                'script_terpasang'  => $s['script_installed'],
                'script_terbaru'    => $s['script_current'],
                'pengawas_panel'    => $s['scheduler'],
                'pengawas_mikhmon'  => collect($s['legacy_schedulers'])->map(fn ($l) => ['nama' => $l['name'], 'aktif' => ! $l['disabled']])->values()->all(),
                'scheduler_sisa'    => $this->schedulerSisa($router),
                'penjualan_ditarik' => $router->last_sales_pull_at?->toIso8601String(),
                'record_tersimpan'  => VoucherSale::where('router_id', $router->id)->count(),
            ];
        });
    }

    private function schedulerSisa(Router $router): ?array
    {
        $berkas = $this->snapshot->terbaru($router);

        if (! $berkas) {
            return null;
        }

        try {
            $d = $this->snapshot->baca($berkas);
        } catch (Throwable) {
            return null;
        }

        return isset($d['scheduler_sisa']) ? ['nama' => $d['scheduler_sisa'], 'snapshot' => $d['diambil'] ?? null] : null;
    }
}
