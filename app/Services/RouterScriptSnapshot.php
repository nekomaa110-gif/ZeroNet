<?php

namespace App\Services;

use App\Models\Router;
use App\Support\MikhmonRecord;
use Illuminate\Support\Facades\File;
use RuntimeException;

class RouterScriptSnapshot
{
    private const FIELD_SCHEDULER = ['start-date', 'start-time', 'interval', 'on-event', 'policy', 'disabled', 'comment'];

    public function __construct(private RouterGateway $gateway) {}

    public function ambil(Router $router): array
    {
        $c         = $this->gateway->klien($router->slug, $router->toConfig(), 25);
        $res       = $c->query('/system/resource/print', ['=.proplist' => 'version,board-name,free-hdd-space,total-hdd-space'])[0] ?? [];
        $users     = $c->query('/ip/hotspot/user/print', ['=.proplist' => 'name,comment']);
        $scheduler = $c->query('/system/scheduler/print', ['=.proplist' => '.id,name,' . implode(',', self::FIELD_SCHEDULER)]);

        return [
            'router'         => $router->slug,
            'diambil'        => now()->toIso8601String(),
            'versi'          => $res['version'] ?? null,
            'board'          => $res['board-name'] ?? null,
            'flash'          => ['sisa' => (int) ($res['free-hdd-space'] ?? 0), 'total' => (int) ($res['total-hdd-space'] ?? 0)],
            'profil'         => $c->query('/ip/hotspot/user/profile/print', ['=.proplist' => '.id,name,on-login,on-logout']),
            'scheduler'      => $scheduler,
            'scheduler_sisa' => $this->schedulerSisa($scheduler, $users),
            'script'         => $this->scriptBukanRecord($c),
            'record'         => $c->count('/system/script/print', ['?comment' => 'mikhmon']),
            'stempel'        => $this->formatStempel($users),
            'service'        => $c->query('/ip/service/print', ['=.proplist' => 'name,port,address,disabled']),
            'user'           => $c->query('/user/print', ['=.proplist' => 'name,group,address,disabled']),
            'grup'           => $c->query('/user/group/print', ['=.proplist' => 'name,policy']),
        ];
    }

    public function simpan(Router $router): array
    {
        $data  = $this->ambil($router);
        $dir   = storage_path("app/cutover/{$router->slug}");
        $nama  = $dir . '/' . now()->format('Ymd-His') . '.json';

        File::ensureDirectoryExists($dir, 0750);
        File::put($nama, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put("{$nama}.sha256", hash_file('sha256', $nama));
        File::chmod($nama, 0640);
        File::chmod("{$nama}.sha256", 0640);

        return ['berkas' => $nama, 'data' => $data];
    }

    public function baca(string $berkas): array
    {
        if (! is_file($berkas) || ! is_file("{$berkas}.sha256")) {
            throw new RuntimeException("Snapshot {$berkas} atau berkas .sha256-nya tidak ada.");
        }

        if (! hash_equals(trim((string) file_get_contents("{$berkas}.sha256")), hash_file('sha256', $berkas))) {
            throw new RuntimeException("Isi snapshot {$berkas} berubah sejak disimpan (hash tidak cocok). Tidak dipakai.");
        }

        return json_decode((string) file_get_contents($berkas), true, flags: JSON_THROW_ON_ERROR);
    }

    public function terbaru(Router $router): ?string
    {
        $semua = preg_grep('#/\d{8}-\d{6}\.json$#', glob(storage_path("app/cutover/{$router->slug}/*.json")) ?: []);
        sort($semua);

        return $semua ? end($semua) : null;
    }

    public function rencanaPulih(Router $router, array $snap): array
    {
        if (($snap['router'] ?? null) !== $router->slug) {
            throw new RuntimeException("Snapshot ini milik router {$snap['router']}, bukan {$router->slug}.");
        }

        $c         = $this->gateway->klien($router->slug, $router->toConfig(), 25);
        $profil    = collect($c->query('/ip/hotspot/user/profile/print', ['=.proplist' => '.id,name,on-login']))->keyBy('name');
        $scheduler = collect($c->query('/system/scheduler/print', ['=.proplist' => '.id,name,' . implode(',', self::FIELD_SCHEDULER)]))->keyBy('name');
        $script    = collect($this->scriptBukanRecord($c))->keyBy('name');
        $langkah   = [];
        $catatan   = [];

        foreach ($snap['profil'] as $p) {
            $kini = $profil[$p['name']] ?? null;

            if (! $kini) {
                $catatan[] = "profil {$p['name']} sudah tidak ada di router, dilewati";
            } elseif (($kini['on-login'] ?? '') !== ($p['on-login'] ?? '')) {
                $langkah[] = ['aksi' => 'set-profil', 'nama' => $p['name'], 'id' => $kini['.id'], 'on-login' => $p['on-login'] ?? ''];
            }
        }

        $diSnap = collect($snap['scheduler'])->keyBy('name');

        foreach ($diSnap as $nama => $s) {
            $kini  = $scheduler[$nama] ?? null;
            $nilai = array_intersect_key($s, array_flip(self::FIELD_SCHEDULER));

            if (! $kini) {
                $langkah[] = ['aksi' => 'tambah-scheduler', 'nama' => $nama, 'nilai' => $nilai];
            } elseif (array_intersect_key($kini, $nilai) != $nilai) {
                $langkah[] = ['aksi' => 'set-scheduler', 'nama' => $nama, 'id' => $kini['.id'], 'nilai' => $nilai];
            }
        }

        foreach ($scheduler as $nama => $s) {
            if ($diSnap->has($nama)) {
                continue;
            }

            if ($nama === config('voucher.scheduler_name')) {
                $langkah[] = ['aksi' => 'hapus-scheduler', 'nama' => $nama, 'id' => $s['.id']];
            } else {
                $catatan[] = "scheduler {$nama} tidak ada di snapshot dan bukan milik panel, dibiarkan";
            }
        }

        $namaScript = (string) config('voucher.script_name');
        if ($script->has($namaScript) && ! collect($snap['script'])->contains('name', $namaScript)) {
            $langkah[] = ['aksi' => 'hapus-script', 'nama' => $namaScript, 'id' => $script[$namaScript]['.id']];
        }

        return ['langkah' => $langkah, 'catatan' => $catatan];
    }

    public function pulihkan(Router $router, array $snap): array
    {
        return $this->gateway->kunciTulis($router->slug, function () use ($router, $snap) {
            $rencana = $this->rencanaPulih($router, $snap);
            $c       = $this->gateway->klien($router->slug, $router->toConfig(), 25);
            $hasil   = [];

            foreach ($rencana['langkah'] as $l) {
                try {
                    match ($l['aksi']) {
                        'set-profil'       => $c->query('/ip/hotspot/user/profile/set', ['=.id' => $l['id'], '=on-login' => $l['on-login']]),
                        'set-scheduler'    => $c->query('/system/scheduler/set', ['=.id' => $l['id']] + $this->param($l['nilai'])),
                        'tambah-scheduler' => $c->query('/system/scheduler/add', ['=name' => $l['nama']] + $this->param($l['nilai'])),
                        'hapus-scheduler'  => $c->query('/system/scheduler/remove', ['=.id' => $l['id']]),
                        'hapus-script'     => $c->query('/system/script/remove', ['=.id' => $l['id']]),
                    };
                    $hasil[] = $l + ['ok' => true];
                } catch (\Throwable $e) {
                    $hasil[] = $l + ['ok' => false, 'error' => $e->getMessage()];
                }
            }

            $sisa = $this->rencanaPulih($router, $snap)['langkah'];

            return ['hasil' => $hasil, 'catatan' => $rencana['catatan'], 'sisa' => $sisa, 'terverifikasi' => $sisa === []];
        }, 5, 300);
    }

    private function param(array $nilai): array
    {
        $out = [];
        foreach ($nilai as $k => $v) {
            if ($v !== '' && $v !== null) {
                $out["={$k}"] = $v;
            }
        }

        return $out;
    }

    private function scriptBukanRecord(MikrotikClient $c): array
    {
        $hasil = [];

        foreach ($c->query('/system/script/print', ['=.proplist' => '.id,name,comment']) as $s) {
            if (($s['comment'] ?? '') === 'mikhmon') {
                continue;
            }

            $hasil[] = $c->query('/system/script/print', [
                '=.proplist' => '.id,name,owner,policy,source,comment,dont-require-permissions',
                '?.id'       => $s['.id'],
            ])[0] ?? $s;
        }

        return $hasil;
    }

    private function schedulerSisa(array $scheduler, array $users): array
    {
        $nama = array_flip(array_column($users, 'name'));

        return array_values(array_filter(
            array_column($scheduler, 'name'),
            fn ($s) => isset($nama[$s]) || (str_starts_with($s, 'zn-') && isset($nama[substr($s, 3)])),
        ));
    }

    private function formatStempel(array $users): array
    {
        $hitung = ['batch' => 0, 'stempel_lama' => 0, 'stempel_iso' => 0, 'kosong' => 0, 'lain' => 0];

        foreach ($users as $u) {
            $cm = trim((string) ($u['comment'] ?? ''));

            $k = match (true) {
                $cm === ''                                      => 'kosong',
                MikhmonRecord::kodeBatch($cm)                   => 'batch',
                (bool) preg_match('#^\d{4}-\d{2}-\d{2} #', $cm) => 'stempel_iso',
                MikhmonRecord::waktu($cm) !== null              => 'stempel_lama',
                default                                         => 'lain',
            };
            $hitung[$k]++;
        }

        return $hitung;
    }
}
