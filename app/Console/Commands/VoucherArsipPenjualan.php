<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\VoucherSalesService;
use Illuminate\Console\Command;
use Throwable;

class VoucherArsipPenjualan extends Command
{
    protected $signature = 'voucher:arsip-penjualan
        {--router= : Slug router tertentu; kosong = semua router}
        {--jalan : Benar-benar hapus record lama dari router (tanpa ini hanya laporan)}';

    protected $description = 'Hapus record penjualan di router yang lebih tua dari bulan berjalan + 3 bulan, hanya yang salinannya sudah ada di panel';

    public function handle(VoucherSalesService $sales): int
    {
        $jalan  = (bool) $this->option('jalan');
        $daftar = Router::query()
            ->when($this->option('router'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        if ($daftar->isEmpty()) {
            $this->error('Tidak ada router yang cocok.');

            return self::FAILURE;
        }

        $gagal = 0;

        foreach ($daftar as $router) {
            try {
                $h = $sales->arsip($router, $jalan);
            } catch (Throwable $e) {
                $gagal++;
                $this->line("  [!]   {$router->name}: {$e->getMessage()}");
                continue;
            }

            $this->line(sprintf(
                '  %s %s: %d record di router, simpan sejak %s, %s %d%s; dilewati: %d tanpa salinan, %d waktu tidak jelas',
                $jalan ? '[ok] ' : '[DRY]',
                $router->name, $h['di_router'], $h['batas'],
                $jalan ? 'dihapus' : 'akan dihapus', $jalan ? $h['dihapus'] : $h['akan_dihapus'],
                $h['per_bulan'] ? ' (' . implode(', ', array_map(fn ($b, $n) => "{$b}: {$n}", array_keys($h['per_bulan']), $h['per_bulan'])) . ')' : '',
                $h['tanpa_salinan'], $h['waktu_ragu'],
            ));

            if ($jalan && $h['dihapus'] > 0) {
                ActivityLogService::log(
                    action: 'voucher_arsip_penjualan',
                    description: "Menghapus {$h['dihapus']} record penjualan lama (sebelum {$h['batas']}) dari router {$router->name}; salinan tetap di panel",
                    subjectType: 'router',
                    subjectId: $router->slug,
                    properties: $h,
                );
            }
        }

        return $gagal === $daftar->count() ? self::FAILURE : self::SUCCESS;
    }
}
