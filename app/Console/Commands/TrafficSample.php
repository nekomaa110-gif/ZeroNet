<?php

namespace App\Console\Commands;

use App\Services\MikrotikService;
use App\Services\TrafficService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TrafficSample extends Command
{
    protected $signature = 'traffic:sample
        {--dry : Tampilkan sampel tanpa menyimpan}';

    protected $description = 'Rekam counter byte WAN semua router (dasar halaman Throughput dan Load Balance)';

    public function handle(TrafficService $traffic, MikrotikService $mikrotik): int
    {
        $dry   = (bool) $this->option('dry');
        $semua = [];

        foreach (array_keys($mikrotik->routers()) as $id) {
            try {
                $rows = $traffic->sample($id);
            } catch (\Throwable $e) {
                $this->line("  [!]  {$id}: {$e->getMessage()}");
                continue;
            }

            if (! $rows) {
                $this->line("  [-]  {$id}: interface WAN tidak ditemukan");
                continue;
            }

            foreach ($rows as $r) {
                $this->line(sprintf('  [OK] %s %s rx=%d tx=%d', $id, $r['interface'], $r['rx_bytes'], $r['tx_bytes']));
            }
            $semua = [...$semua, ...$rows];
        }

        if ($dry) {
            $this->line('mode --dry, tidak ada yang disimpan');
            return self::SUCCESS;
        }

        if ($semua) {
            DB::table(TrafficService::TABLE)->insert($semua);
        }

        $hapus = DB::table(TrafficService::TABLE)
            ->where('sampled_at', '<', now()->subDays(TrafficService::KEEP_DAYS))
            ->delete();

        $this->line(sprintf('tersimpan %d baris, dihapus %d baris lama', count($semua), $hapus));

        return self::SUCCESS;
    }
}
