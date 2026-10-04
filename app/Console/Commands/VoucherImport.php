<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\VoucherImportService;
use Illuminate\Console\Command;

class VoucherImport extends Command
{
    protected $signature = 'voucher:import
        {--router= : Slug router (wajib)}
        {--dry : Hanya tampilkan rencana, jangan simpan}';

    protected $description = 'Impor voucher Mikhmon dari router ke database panel; aman diulang (unik router + username)';

    public function handle(VoucherImportService $import): int
    {
        $router = Router::where('slug', (string) $this->option('router'))->first();

        if (! $router) {
            $this->error('Isi --router dengan slug router yang ada.');

            return self::FAILURE;
        }

        if ($this->option('dry')) {
            $p = VoucherImportService::pratinjau($import->rencana($router));
            $this->tampilkan($p['ringkasan']);
            $this->table(['Batch', 'Kartu', 'Profil', 'Mode'], array_map(
                fn ($b) => [$b['kode'], $b['jumlah'], $b['profile'], $b['mode']],
                array_slice($p['batch'], 0, 40),
            ));

            return self::SUCCESS;
        }

        $hasil = $import->impor($router);
        $this->tampilkan($hasil['ringkasan']);
        $this->info(sprintf(
            '%d voucher masuk dalam %d batch; status: %d terpakai, %d habis, %d hilang; %d penjualan tertaut.',
            $hasil['masuk'], $hasil['batch'], $hasil['keadaan']['aktif'], $hasil['keadaan']['habis'], $hasil['keadaan']['hilang'], $hasil['penjualan_tertaut'],
        ));

        ActivityLogService::log(
            action: 'voucher_import',
            description: "Impor voucher Mikhmon dari {$router->name}: {$hasil['masuk']} voucher baru dalam {$hasil['batch']} batch",
            subjectType: 'router',
            subjectId: $router->slug,
            properties: $hasil,
        );

        return self::SUCCESS;
    }

    private function tampilkan(array $r): void
    {
        $this->line(sprintf(
            '  %d user di router: %d belum dipakai, %d terpakai (%d tanpa asal batch), %d sudah ada di panel, %d bukan voucher, %d nama kembar',
            $r['user_router'], $r['belum_dipakai'], $r['terpakai'], $r['terpakai_tanpa_batch'], $r['sudah_ada'], $r['bukan_voucher'], $r['nama_kembar'],
        ));
    }
}
