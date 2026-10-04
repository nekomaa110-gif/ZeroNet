<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class LogsArchive extends Command
{
    protected $signature = 'logs:archive';
    protected $description = 'Archive activity_logs bulan sebelumnya ke CSV+ZIP (jalankan SEBELUM cleanup)';

    public function handle(): int
    {
        $start = now()->subMonth()->startOfMonth();
        $end   = now()->subMonth()->endOfMonth();
        $tag   = $start->format('Y-m');
        $dir   = storage_path('app/log-archives');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $csvPath = "{$dir}/activity_logs-{$tag}.csv";
        $fh = fopen($csvPath, 'w');
        $first = true;
        $count = 0;

        DB::table('activity_logs')
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->chunk(2000, function ($rows) use ($fh, &$first, &$count) {
                foreach ($rows as $row) {
                    $arr = (array) $row;
                    if ($first) {
                        fputcsv($fh, array_keys($arr));
                        $first = false;
                    }
                    fputcsv($fh, $arr);
                    $count++;
                }
            });
        fclose($fh);

        if ($count === 0) {
            @unlink($csvPath);
            $this->line("  Tidak ada activity_logs di bulan {$tag}, archive dilewati.");
            return self::SUCCESS;
        }

        $zipPath = "{$dir}/activity_logs-{$tag}.zip";
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFile($csvPath, basename($csvPath));
            $zip->close();
            @unlink($csvPath);
            $this->info("  ZIP: {$zipPath} ({$count} rows)");
        } else {
            $this->error("  Gagal buat ZIP {$zipPath}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
