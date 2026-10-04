<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\RouterGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RadacctReconcile extends Command
{
    protected $signature = 'radacct:reconcile
        {--dry-run : Tampilkan baris yang akan ditutup tanpa mengeksekusi}
        {--routers= : Slug router dipisah koma, override daftar bawaan}';

    protected $description = 'Tutup baris radacct yang sesinya sudah tidak ada di router (cocokkan username+MAC lewat API)';

    private const GRACE_MINUTES = 3;

    private const API_TIMEOUT = 8;

    private const SAMPLE_LIMIT = 20;

    public function handle(RouterGateway $gateway): int
    {
        $dry    = (bool) $this->option('dry-run');
        $nasMap = (array) config('radius.nas_map', []);
        $perNas = $nasMap !== [];
        $slugs  = $this->option('routers')
            ? array_values(array_filter(array_map('trim', explode(',', $this->option('routers')))))
            : Router::orderBy('sort_order')->orderBy('name')->pluck('slug')->all();

        if (! $slugs) {
            $this->warn('  [SKIP] tidak ada router di tabel routers');

            return self::SUCCESS;
        }

        if (! $perNas && ! $dry && $this->option('routers')) {
            $sisa = array_diff(Router::pluck('slug')->all(), $slugs);
            if ($sisa) {
                $this->error('  --routers sebagian hanya boleh dipakai dengan --dry-run atau RADACCT_NAS_MAP, '
                    . 'supaya sesi di ' . implode(', ', $sisa) . ' tidak ikut ditutup.');

                return self::FAILURE;
            }
        }

        $hidup     = [];
        $perRouter = [];
        $gagal     = [];

        foreach ($slugs as $slug) {
            $router = Router::where('slug', $slug)->first();
            if (! $router) {
                $gagal[$slug]     = 'slug tidak ada di tabel routers';
                $perRouter[$slug] = ['status' => 'GAGAL', 'error' => $gagal[$slug]];
                $this->line("  [!]   {$slug}: tidak ada di tabel routers");
                continue;
            }

            $mulai = microtime(true);
            try {
                $rows = $gateway->jalankan(
                    $slug,
                    $router->toConfig(),
                    fn ($c) => $c->query('/ip/hotspot/active/print'),
                    self::API_TIMEOUT,
                );
            } catch (\Throwable $e) {
                $gagal[$slug]     = $e->getMessage();
                $perRouter[$slug] = ['status' => 'GAGAL', 'error' => $e->getMessage()];
                $this->line("  [!]   {$slug}: GAGAL ({$e->getMessage()})");
                continue;
            }

            $hidup[$slug] = [];
            $radius       = 0;
            foreach ($rows as $row) {
                if (($row['radius'] ?? 'false') !== 'true') {
                    continue;
                }
                $hidup[$slug][$this->kunci($row['user'] ?? '', $row['mac-address'] ?? '')] = true;
                $radius++;
            }

            $ms               = (int) round((microtime(true) - $mulai) * 1000);
            $perRouter[$slug] = [
                'status'      => 'OK',
                'sesi_total'  => count($rows),
                'sesi_radius' => $radius,
                'ms'          => $ms,
            ];

            if ($perNas && empty($nasMap[$slug])) {
                $perRouter[$slug]['catatan'] = 'tidak ada di RADACCT_NAS_MAP, sesinya tidak disentuh';
            }

            $this->line(sprintf('  [OK]  %s: %d sesi (%d radius), %d ms', $slug, count($rows), $radius, $ms));
        }

        if ($gagal && ! $perNas) {
            $catatan = 'dibatalkan, router tidak terjawab: ' . implode(', ', array_keys($gagal));
            $this->warn("  [SKIP] {$catatan} (tidak ada baris yang ditutup)");
            $this->catat($perRouter, 0, [], $catatan, $dry, $perNas);

            return self::SUCCESS;
        }

        $tutup = $perNas
            ? $this->yatimPerNas($hidup, $nasMap)
            : $this->yatimSemua($hidup);

        $catatan = $gagal
            ? 'sebagian, router tidak terjawab: ' . implode(', ', array_keys($gagal)) . ' (sesinya tidak disentuh)'
            : null;

        if ($catatan) {
            $this->warn("  [!]   {$catatan}");
        }

        $contoh = array_map(
            fn ($r) => ['user' => $r->username, 'mac' => $r->callingstationid, 'nas' => $r->nasipaddress],
            array_slice($tutup, 0, self::SAMPLE_LIMIT)
        );

        if (! $tutup) {
            $this->line('  [OK]  tidak ada baris yatim, radacct sinkron dengan router');
            if ($catatan) {
                $this->catat($perRouter, 0, [], $catatan, $dry, $perNas);
            }

            return self::SUCCESS;
        }

        if ($dry) {
            $this->line(sprintf('  [DRY] %d baris akan ditutup:', count($tutup)));
            foreach ($contoh as $c) {
                $this->line("        - {$c['user']} ({$c['mac']}) NAS {$c['nas']}");
            }
            $this->catat($perRouter, count($tutup), $contoh, $catatan, true, $perNas);

            return self::SUCCESS;
        }

        foreach (array_chunk(array_column($tutup, 'radacctid'), 500) as $chunk) {
            DB::table('radacct')->whereIn('radacctid', $chunk)->whereNull('acctstoptime')->update([
                'acctstoptime'       => DB::raw('COALESCE(acctupdatetime, acctstarttime)'),
                'acctsessiontime'    => DB::raw('GREATEST(TIMESTAMPDIFF(SECOND, acctstarttime, COALESCE(acctupdatetime, acctstarttime)), 0)'),

                'acctterminatecause' => 'NAS-Request',
            ]);
        }

        $this->line(sprintf('  [OK]  %d baris yatim ditutup', count($tutup)));
        $this->catat($perRouter, count($tutup), $contoh, $catatan, false, $perNas);

        return self::SUCCESS;
    }

    private function kandidat()
    {
        return DB::table('radacct')
            ->whereNull('acctstoptime')
            ->where('acctstarttime', '<', now()->subMinutes(self::GRACE_MINUTES));
    }

    private function yatimSemua(array $hidup): array
    {
        $semua = array_merge([], ...array_values($hidup));

        $tutup = [];
        foreach ($this->kandidat()->get(['radacctid', 'username', 'callingstationid', 'nasipaddress']) as $row) {
            if (! isset($semua[$this->kunci($row->username, $row->callingstationid)])) {
                $tutup[] = $row;
            }
        }

        return $tutup;
    }

    private function yatimPerNas(array $hidup, array $nasMap): array
    {
        $pemilik = [];
        foreach ($hidup as $slug => $_) {
            foreach ($nasMap[$slug] ?? [] as $ip) {
                $pemilik[$ip] = $slug;
            }
        }

        if (! $pemilik) {
            return [];
        }

        $tutup = [];
        $rows  = $this->kandidat()
            ->whereIn('nasipaddress', array_keys($pemilik))
            ->get(['radacctid', 'username', 'callingstationid', 'nasipaddress']);

        foreach ($rows as $row) {
            $slug = $pemilik[$row->nasipaddress] ?? null;
            if ($slug && ! isset($hidup[$slug][$this->kunci($row->username, $row->callingstationid)])) {
                $tutup[] = $row;
            }
        }

        return $tutup;
    }

    private function kunci(string $user, string $mac): string
    {
        return strtoupper(trim($user)) . '|' . strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
    }

    private function catat(array $perRouter, int $ditutup, array $contoh, ?string $catatan, bool $dry, bool $perNas): void
    {
        $berhasil = array_keys(array_filter($perRouter, fn ($d) => $d['status'] === 'OK'));
        $gagal    = array_keys(array_filter($perRouter, fn ($d) => $d['status'] !== 'OK'));
        $sesi     = array_sum(array_map(fn ($d) => $d['sesi_radius'] ?? 0, $perRouter));

        if ($catatan !== null && ! $perNas) {
            $deskripsi = sprintf(
                'Sinkronisasi sesi pelanggan dibatalkan karena %s tidak merespons. Tidak ada catatan yang diubah.',
                $this->rangkai($gagal)
            );
        } elseif ($ditutup === 0) {
            $deskripsi = sprintf(
                'Sinkronisasi sesi pelanggan: %d sesi aktif di %s, semuanya cocok dengan catatan. Tidak ada yang perlu ditutup.',
                $sesi,
                $this->rangkai($berhasil)
            );
        } else {
            $deskripsi = sprintf(
                'Sinkronisasi sesi pelanggan: %d catatan sesi ditutup karena sesinya sudah tidak ada di router, dari %d sesi aktif di %s.',
                $ditutup,
                $sesi,
                $this->rangkai($berhasil)
            );
        }

        if ($catatan !== null && $perNas) {
            $deskripsi .= sprintf(' %s tidak merespons, sesi di router itu tidak disentuh.', ucfirst($this->rangkai($gagal)));
        }

        if ($dry) {
            $deskripsi = 'Uji coba, tidak ada perubahan, ' . lcfirst($deskripsi);
        }

        ActivityLogService::log(
            action: 'radacct.reconcile',
            description: $deskripsi,
            properties: [
                'router'  => $perRouter,
                'ditutup' => $ditutup,
                'contoh'  => $contoh,
                'catatan' => $catatan,
                'dry_run' => $dry,
                'per_nas' => $perNas,
            ],
        );
    }

    private function rangkai(array $nama): string
    {
        if (! $nama) {
            return 'router mana pun';
        }
        if (count($nama) === 1) {
            return $nama[0];
        }

        $akhir = array_pop($nama);

        return implode(', ', $nama) . ' dan ' . $akhir;
    }
}
