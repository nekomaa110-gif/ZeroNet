<?php

namespace App\Console\Commands;

use App\Jobs\SyncVoucherBatch;
use App\Models\Router;
use App\Models\VoucherBatch;
use App\Services\ActivityLogService;
use App\Services\VoucherRouterService;
use App\Services\VoucherService;
use Illuminate\Console\Command;
use Throwable;

class VoucherSinkron extends Command
{
    protected $signature = 'voucher:sinkron
        {--router= : Slug router tertentu; kosong = semua router}
        {--dry : Hanya tampilkan, jangan ubah apa pun}
        {--no-push : Jangan kirim ulang batch yang gagal ke router}';

    protected $description = 'Samakan status voucher panel dengan router, lalu kirim ulang batch yang belum tuntas';

    public function handle(VoucherService $service, VoucherRouterService $routers): int
    {
        $dry = (bool) $this->option('dry');

        $daftar = Router::query()
            ->when($this->option('router'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        if ($daftar->isEmpty()) {
            $this->error('Tidak ada router yang cocok.');
            return self::FAILURE;
        }

        $total = ['diperiksa' => 0, 'aktif' => 0, 'habis' => 0, 'hilang' => 0];
        $gagal = [];

        foreach ($daftar as $router) {
            if (! VoucherBatch::where('router_id', $router->id)->exists()) {
                $this->line("  ·     {$router->name}: belum ada voucher, dilewati");
                continue;
            }

            $mulai = microtime(true);

            try {
                $angka = $service->reconcile($router, $dry);
            } catch (Throwable $e) {
                $gagal[$router->slug] = $e->getMessage();
                $this->line("  [!]   {$router->name}: {$e->getMessage()}");
                continue;
            } finally {
                $routers->disconnect($router);
            }

            foreach ($angka as $k => $v) {
                $total[$k] += $v;
            }

            $this->line(sprintf(
                '  [ok]  %s: %d kartu diperiksa, %d terpakai, %d habis, %d hilang (%.1fs)',
                $router->name, $angka['diperiksa'], $angka['aktif'], $angka['habis'], $angka['hilang'],
                microtime(true) - $mulai,
            ));
        }

        $tertunda = 0;

        if (! $this->option('no-push')) {
            $batches = VoucherBatch::query()
                ->whereIn('status', ['pending', 'partial', 'failed', 'syncing'])
                ->when($this->option('router'), fn ($q, $slug) => $q->whereHas('router', fn ($r) => $r->where('slug', $slug)))
                ->whereNotNull('router_id')

                ->where('updated_at', '<', now()->subMinutes(10))
                ->get();

            foreach ($batches as $batch) {
                $sisa = $batch->vouchers()->where('sync_status', '!=', 'success')->count();

                if ($sisa === 0) {
                    if (! $dry) {
                        $service->rangkumBatch($batch);
                    }
                    continue;
                }

                $this->line("  [>]   Batch {$batch->code}: {$sisa} voucher belum masuk router, dikirim ulang");

                if (! $dry) {
                    SyncVoucherBatch::dispatch($batch->id);
                }

                $tertunda++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d kartu diperiksa, %d terpakai, %d habis, %d hilang, %d batch dikirim ulang.',
            $dry ? '[DRY] ' : '', $total['diperiksa'], $total['aktif'], $total['habis'], $total['hilang'], $tertunda,
        ));

        if (! $dry && ($total['diperiksa'] > 0 || $tertunda > 0)) {
            ActivityLogService::log(
                action: 'voucher_sinkron',
                description: "Sinkronisasi voucher: {$total['diperiksa']} kartu diperiksa, {$tertunda} batch dikirim ulang",
                subjectType: 'voucher',
                properties: $total + ['batch_dikirim_ulang' => $tertunda, 'router_gagal' => $gagal],
            );
        }

        return $gagal ? self::FAILURE : self::SUCCESS;
    }
}
