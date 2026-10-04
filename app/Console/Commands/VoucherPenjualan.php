<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\VoucherSalesService;
use Illuminate\Console\Command;
use Throwable;

class VoucherPenjualan extends Command
{
    protected $signature = 'voucher:penjualan
        {--router= : Slug router tertentu; kosong = semua router}
        {--penuh : Baca semua record walau jumlahnya di router tidak berubah}
        {--dry : Hitung record baru tanpa menyimpan}';

    protected $description = 'Tarik record penjualan Mikhmon (/system script comment=mikhmon) ke voucher_sales; aman diulang, tanpa batas waktu';

    public function handle(VoucherSalesService $sales): int
    {
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
            $mulai = microtime(true);

            try {
                $h = $sales->tarik($router, (bool) $this->option('penuh'), (bool) $this->option('dry'));
            } catch (Throwable $e) {
                $gagal++;
                $this->line("  [!]   {$router->name}: {$e->getMessage()}");
                continue;
            }

            $isi = $h['dilewati']
                ? 'jumlah tidak berubah'
                : "{$h['baru']} baru" . ($h['tak_terurai'] ? ", {$h['tak_terurai']} tak terurai" : '');

            $this->line(sprintf(
                '  %s %s: %d record di router, %s (%.1fs)',
                $this->option('dry') ? '[DRY]' : '[ok] ',
                $router->name, $h['di_router'], $isi, microtime(true) - $mulai,
            ));
        }

        return $gagal === $daftar->count() ? self::FAILURE : self::SUCCESS;
    }
}
