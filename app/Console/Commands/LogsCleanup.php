<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LogsCleanup extends Command
{
    protected $signature = 'logs:cleanup {--dry-run : Tampilkan jumlah row tanpa eksekusi}';
    protected $description = 'Hapus log lama: radpostauth >7 hari, activity_logs >30 hari, sessions expired';

    private const CHUNK = 5000;

    public function handle(): int
    {
        $dry = $this->option('dry-run');
        $sessionLifetimeMin = (int) config('session.lifetime', 120);
        $sessionCutoff = now()->subMinutes($sessionLifetimeMin * 2)->timestamp;

        $jobs = [
            [
                'label'    => 'radpostauth (>7 hari)',
                'count_q'  => fn () => DB::table('radpostauth')->where('authdate', '<', now()->subDays(7))->count(),
                'delete_q' => fn () => DB::table('radpostauth')->where('authdate', '<', now()->subDays(7))->limit(self::CHUNK)->delete(),
            ],
            [
                'label'    => 'activity_logs (>30 hari)',
                'count_q'  => fn () => DB::table('activity_logs')->where('created_at', '<', now()->subDays(30))->count(),
                'delete_q' => fn () => DB::table('activity_logs')->where('created_at', '<', now()->subDays(30))->limit(self::CHUNK)->delete(),
            ],
            [
                'label'    => "sessions (expired >{$sessionLifetimeMin}m × 2)",
                'count_q'  => fn () => DB::table('sessions')->where('last_activity', '<', $sessionCutoff)->count(),
                'delete_q' => fn () => DB::table('sessions')->where('last_activity', '<', $sessionCutoff)->limit(self::CHUNK)->delete(),
            ],
        ];

        foreach ($jobs as $job) {
            if ($dry) {
                $this->line(sprintf('  [DRY] %s: %d row akan dihapus', $job['label'], ($job['count_q'])()));
                continue;
            }

            $totalDeleted = 0;
            do {
                $deleted = ($job['delete_q'])();
                $totalDeleted += $deleted;
                if ($deleted >= self::CHUNK) {
                    usleep(100_000);
                }
            } while ($deleted >= self::CHUNK);

            $this->line(sprintf('  [OK]  %s: %d row dihapus', $job['label'], $totalDeleted));
        }

        return self::SUCCESS;
    }
}
