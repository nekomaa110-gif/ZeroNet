<?php

namespace App\Console\Commands;

use App\Services\RouterLogService;
use Illuminate\Console\Command;

class RouterLogBersihkan extends Command
{
    protected $signature = 'router:log-bersihkan';

    protected $description = 'Hapus log hotspot router yang lebih tua dari ' . RouterLogService::SIMPAN_HARI . ' hari';

    public function handle(RouterLogService $log): int
    {
        $this->line('  ' . $log->bersihkan() . ' baris log router dihapus');

        return self::SUCCESS;
    }
}
