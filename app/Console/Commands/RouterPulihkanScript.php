<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\RouterScriptSnapshot;
use Illuminate\Console\Command;
use RuntimeException;

class RouterPulihkanScript extends Command
{
    protected $signature = 'router:pulihkan-script
        {--router= : Slug router (wajib)}
        {--berkas= : Snapshot yang dipakai; kosong = snapshot terbaru router itu}
        {--jalan : Benar-benar tulis ke router (tanpa ini hanya menampilkan langkah)}';

    protected $description = 'Kembalikan on-login profil, scheduler, dan script panel ke keadaan snapshot (rollback cut-over); tidak menyentuh service, user, firewall, atau L2TP';

    public function handle(RouterScriptSnapshot $snapshot): int
    {
        $router = Router::where('slug', (string) $this->option('router'))->first();

        if (! $router) {
            $this->error('Isi --router dengan slug router yang ada.');

            return self::FAILURE;
        }

        $berkas = $this->option('berkas') ?: $snapshot->terbaru($router);

        if (! $berkas) {
            $this->error("Belum ada snapshot untuk {$router->name}. Jalankan router:simpan-script dulu.");

            return self::FAILURE;
        }

        try {
            $snap = $snapshot->baca($berkas);
        } catch (RuntimeException $e) {
            $this->error('  ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line("  Snapshot {$berkas} (diambil {$snap['diambil']})");

        if (! $this->option('jalan')) {
            $rencana = $snapshot->rencanaPulih($router, $snap);
            $this->tampilkan($rencana['langkah']);
            $this->catatan($rencana['catatan']);
            $this->line($rencana['langkah'] ? '  [DRY] tidak ada yang ditulis. Ulangi dengan --jalan untuk menerapkan.' : '  [ok]  router sudah sama dengan snapshot.');

            return self::SUCCESS;
        }

        $hasil = $snapshot->pulihkan($router, $snap);

        foreach ($hasil['hasil'] as $h) {
            $this->line(sprintf('  %s %s %s%s', $h['ok'] ? '[ok] ' : '[!]  ', $h['aksi'], $h['nama'], $h['ok'] ? '' : ': ' . $h['error']));
        }
        $this->catatan($hasil['catatan']);
        if ($hasil['terverifikasi']) {
            $this->info('  Terverifikasi: on-login, scheduler, dan script panel sama dengan snapshot.');
        } else {
            $this->error('  Belum sama dengan snapshot, sisa ' . count($hasil['sisa']) . ' langkah. Periksa manual.');
        }

        ActivityLogService::log(
            action: 'router_pulihkan_script',
            description: "Memulihkan on-login/scheduler router {$router->name} dari snapshot " . basename($berkas) . ($hasil['terverifikasi'] ? '' : ' (belum tuntas)'),
            subjectType: 'router',
            subjectId: $router->slug,
            properties: ['berkas' => basename($berkas), 'langkah' => array_map(fn ($h) => array_diff_key($h, ['nilai' => 1, 'on-login' => 1]), $hasil['hasil'])],
        );

        return $hasil['terverifikasi'] ? self::SUCCESS : self::FAILURE;
    }

    private function tampilkan(array $langkah): void
    {
        foreach ($langkah as $l) {
            $this->line("  [>]   {$l['aksi']} {$l['nama']}");
        }
    }

    private function catatan(array $catatan): void
    {
        foreach ($catatan as $c) {
            $this->line("  [i]   {$c}");
        }
    }
}
