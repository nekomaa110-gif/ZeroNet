<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\RouterScriptSnapshot;
use Illuminate\Console\Command;
use Throwable;

class RouterSimpanScript extends Command
{
    protected $signature = 'router:simpan-script {--router= : Slug router tertentu; kosong = semua router}';

    protected $description = 'Simpan snapshot on-login profil, scheduler, script, service, dan user router ke storage/app/cutover (hanya baca router)';

    public function handle(RouterScriptSnapshot $snapshot): int
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
            try {
                ['berkas' => $berkas, 'data' => $d] = $snapshot->simpan($router);
            } catch (Throwable $e) {
                $gagal++;
                $this->line("  [!]   {$router->name}: {$e->getMessage()}");
                continue;
            }

            $sisaFlash = $d['flash']['total'] > 0 ? round($d['flash']['sisa'] / 1048576, 1) . ' MB dari ' . round($d['flash']['total'] / 1048576, 1) . ' MB' : '-';

            $this->line("  [ok]  {$router->name} ({$d['versi']}), flash sisa {$sisaFlash}");
            $this->line('        profil: ' . collect($d['profil'])->map(fn ($p) => $p['name'] . '=' . $this->jenisOnLogin($p['on-login'] ?? ''))->implode(', '));
            $this->line('        scheduler: ' . (collect($d['scheduler'])->pluck('name')->implode(', ') ?: '-'));
            $this->line('        script non-record: ' . (collect($d['script'])->pluck('name')->implode(', ') ?: '-') . "; record penjualan: {$d['record']}");
            $this->line('        comment user: ' . collect($d['stempel'])->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));
            $this->line("        disimpan ke {$berkas}");

            if ($d['scheduler_sisa'] !== []) {
                $this->warn('        [!] scheduler sisa on-login (voucher tidak pernah distempel dan penjualannya tidak tercatat): ' . implode(', ', $d['scheduler_sisa']));
            }

            if (str_starts_with((string) $d['versi'], '7.') && $d['stempel']['stempel_lama'] > 0) {
                $this->warn("        [!] {$d['stempel']['stempel_lama']} voucher ber-stempel format lama di router ROS 7: scheduler expire panel menangani dua format, scheduler Mikhmon hasil edit tidak.");
            }
        }

        return $gagal === $daftar->count() ? self::FAILURE : self::SUCCESS;
    }

    private function jenisOnLogin(string $onLogin): string
    {
        return match (true) {
            $onLogin === ''                                        => 'kosong',
            str_contains($onLogin, (string) config('voucher.script_name')) => 'panel',
            str_contains($onLogin, ':put (",')                     => 'mikhmon',
            default                                                => 'lain',
        };
    }
}
