<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

class RouterRutin extends Command
{
    protected $signature = 'router:rutin {--sinkron : Paksa ikut menjalankan voucher:sinkron}';

    protected $description = 'Tugas router tiap 5 menit dalam satu proses supaya berbagi satu sesi API per router (trafik, radacct, penjualan, sinkron voucher)';

    public function handle(): int
    {
        $langkah = ['traffic:sample', 'radacct:reconcile', 'voucher:penjualan'];

        if ($this->option('sinkron') || now()->minute % 15 === 0) {
            $langkah[] = 'voucher:sinkron';
        }

        $gagal = 0;

        foreach ($langkah as $perintah) {
            $this->line("== {$perintah}");

            try {
                if ($this->call($perintah) !== self::SUCCESS) {
                    $gagal++;
                }
            } catch (Throwable $e) {
                $gagal++;
                $this->error("  {$perintah} gagal: {$e->getMessage()}");
            }
        }

        return $gagal === count($langkah) ? self::FAILURE : self::SUCCESS;
    }
}
