<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\LoadBalanceService;
use App\Services\MikrotikService;
use App\Services\RouterGateway;
use App\Services\RouterLogService;
use App\Services\RouterSnapshotService;
use Exception;
use Illuminate\Console\Command;
use Throwable;

class MikrotikPoll extends Command
{
    protected $signature = 'mikrotik:poll
        {--once : Satu putaran semua tugas lalu tampilkan ringkasan snapshot}
        {--router= : Hanya router ini (slug)}
        {--max-time=3600 : Keluar setelah sekian detik supaya PM2 memuat kode baru}';

    protected $description = 'Poller API MikroTik: koneksi permanen per router, hasil ke Redis untuk halaman live panel (hanya baca)';

    private const TIMEOUT = 4;

    private const MAX_MEMORY = 96 * 1024 * 1024;

    private const TUGAS = [
        'ifaces'   => [2, 30],
        'resource' => [10, 60],
        'identity' => [300, 300],
        'detect'   => [45, 45],
        'routes'   => [5, 60],
        'conn'     => [30, 0],
        'hotspot'  => [10, 0],
        'cookie'   => [30, 0],
        'log'      => [30, 300],
    ];

    private const TUGAS_HOTSPOT = ['hotspot', 'cookie'];

    private const PROPS_ACTIVE = '.id,user,address,mac-address,uptime,idle-time,bytes-in,bytes-out,login-by,server';

    private const PROPS_HOST = '.id,mac-address,address,to-address,authorized,bypassed,server,idle-time';

    private const PROPS_COOKIE = '.id,user,mac-address,expires-in';

    private const BACKOFF = [5, 10, 30];

    private MikrotikService $mt;

    private LoadBalanceService $lb;

    private RouterSnapshotService $snap;

    private array $state = [];

    public function handle(MikrotikService $mt, LoadBalanceService $lb, RouterSnapshotService $snap, RouterGateway $gateway): int
    {
        $gateway->aturKonteks(RouterGateway::POLLER);
        $this->mt   = $mt;
        $this->lb   = $lb;
        $this->snap = $snap;
        $mt->limitTimeout(self::TIMEOUT);

        $once    = (bool) $this->option('once');
        $batas   = time() + max(60, (int) $this->option('max-time'));
        $routers = [];
        $muat    = 0;

        if (! $once) {
            $this->catat('mulai, pid ' . getmypid());
        }

        while (true) {
            if (time() >= $muat) {
                $routers = $this->daftarRouter($routers);
                $muat    = time() + 60;
            }

            foreach (array_keys($routers) as $id) {
                $this->putar($id, $once);
            }

            if ($once) {
                break;
            }
            $snap->beat();
            if (time() >= $batas || memory_get_usage(true) > self::MAX_MEMORY) {
                $this->catat('berhenti terjadwal, PM2 akan menyalakan ulang');
                break;
            }

            usleep((int) ((1 - fmod(microtime(true), 1)) * 1e6));
        }

        if ($once) {
            foreach (array_keys($routers) as $id) {
                $this->ringkas($id, $snap->get($id));
            }
        }

        return self::SUCCESS;
    }

    private function daftarRouter(array $lama): array
    {
        $hanya = (string) $this->option('router');
        $baru  = [];
        foreach ($this->mt->routers() as $id => $cfg) {
            if ($hanya !== '' && $id !== $hanya) {
                continue;
            }
            $baru[$id] = md5(json_encode($cfg));
        }

        foreach ($lama as $id => $sidik) {
            if (($baru[$id] ?? null) !== $sidik) {
                $this->mt->forgetClient($id);
                unset($this->state[$id]);
                if (! isset($baru[$id])) {
                    $this->snap->forget($id);
                }
            }
        }

        return $baru;
    }

    private function putar(string $id, bool $once): void
    {
        $now = microtime(true);
        $this->state[$id] ??= [
            'akhir' => [],
            'gagal' => 0,
            'tunda' => 0.0,
            'snap'  => $this->snap->get($id) ?? ['online' => false, 'error' => null, 'ifaces' => []],
        ];
        $st = &$this->state[$id];

        if ($now < $st['tunda']) {
            return;
        }

        $dilihat = $once || $this->snap->watched($id);
        $hotspot = $once || $this->snap->watchedHotspot($id);
        $log     = $once || $this->snap->watchedLog($id);
        $det     = $this->lb->cached($id);
        $jalan   = false;

        try {
            foreach (self::TUGAS as $tugas => [$cepat, $lambat]) {
                $segera = match (true) {
                    $tugas === 'log'                            => $log,
                    in_array($tugas, self::TUGAS_HOTSPOT, true) => $hotspot,
                    default                                     => $dilihat,
                };
                $jeda = $segera ? $cepat : $lambat;
                if ($jeda === 0 || ! $this->perlu($tugas, $det)) {
                    continue;
                }
                if ($now - ($st['akhir'][$tugas] ?? 0) < $jeda) {
                    continue;
                }

                $this->kerjakan($id, $tugas, $st['snap'], $det);
                $st['akhir'][$tugas] = $now;
                $jalan = true;
            }

            if ($jalan && ! ($st['snap']['online'] ?? false)) {
                $this->catat("{$id}: online");
            }
            if ($jalan) {
                $st['snap']['online'] = true;
                $st['snap']['error']  = null;
                $st['gagal']          = 0;
            }
        } catch (Exception $e) {
            $this->mt->forgetClient($id);
            $st['gagal']++;
            $st['tunda'] = $now + self::BACKOFF[min($st['gagal'], count(self::BACKOFF)) - 1];
            if (($st['snap']['online'] ?? true) || $st['gagal'] === 1) {
                $this->catat("{$id}: gagal, coba lagi {$this->jeda($st['tunda'] - $now)} ({$e->getMessage()})");
            }
            $st['snap']['online']   = false;
            $st['snap']['error']    = $e->getMessage();
            $st['snap']['error_at'] = $now;
            $jalan = true;
        }

        if ($jalan) {
            $this->snap->put($id, $st['snap']);
        }
    }

    private function perlu(string $tugas, ?array $det): bool
    {
        return match ($tugas) {
            'routes' => (bool) $det,
            'conn'   => $det && $det['method'] !== 'ecmp',
            default  => true,
        };
    }

    private function kerjakan(string $id, string $tugas, array &$s, ?array &$det): void
    {
        $t = microtime(true);

        try {
            switch ($tugas) {
                case 'ifaces':
                    $rows = $this->mt->q($id, '/interface/print', ['=.proplist' => MikrotikService::IFACE_PROPS]);
                    $hist = [...($s['ifaces'] ?? []), ['ts' => microtime(true), 'c' => RouterSnapshotService::counterMap($rows)]];
                    $segar = array_values(array_filter($hist, fn ($h) => $t - $h['ts'] <= RouterSnapshotService::HIST_SECONDS));
                    $s['ifaces'] = count($segar) >= 2 ? $segar : array_slice($hist, -2);
                    break;

                case 'resource':
                    $s['resource'] = ['ts' => $t, 'data' => $this->mt->q($id, '/system/resource/print', ['=.proplist' => MikrotikService::RESOURCE_PROPS])[0] ?? []];
                    break;

                case 'identity':
                    $s['identity'] = $this->mt->q($id, '/system/identity/print')[0]['name'] ?? '-';
                    break;

                case 'detect':
                    $det = $this->lb->detect($id, true);
                    break;

                case 'routes':
                    $s['routes']    = ['ts' => $t, 'data' => $this->mt->q($id, '/ip/route/print')];
                    $s['addresses'] = ['ts' => $t, 'data' => $this->mt->q($id, '/ip/address/print')];
                    break;

                case 'conn':
                    $s['conn'] = ['ts' => $t, 'data' => $this->lb->countConnections($id)];
                    break;

                case 'hotspot':
                    $this->snap->putHotspot($id, 'active', $this->mt->q($id, '/ip/hotspot/active/print', ['=.proplist' => self::PROPS_ACTIVE]));
                    $this->snap->putHotspot($id, 'host', $this->mt->q($id, '/ip/hotspot/host/print', ['=.proplist' => self::PROPS_HOST]));
                    break;

                case 'cookie':
                    $this->snap->putHotspot($id, 'cookie', $this->mt->q($id, '/ip/hotspot/cookie/print', ['=.proplist' => self::PROPS_COOKIE]));
                    break;

                case 'log':
                    $router = Router::where('slug', $id)->first();
                    if (! $router) {
                        break;
                    }
                    $jam  = $this->mt->q($id, '/system/clock/print')[0] ?? [];
                    $baca = $this->mt->q($id, '/log/print', ['=.proplist' => '.id,time,topics,message']);
                    try {
                        $hasil = app(RouterLogService::class)->simpan($router, $baca, $jam);
                        if ($hasil['celah']) {
                            $this->catat("{$id}: log router terlewat sebagian (buffer berputar lebih cepat dari jeda baca)");
                        }
                    } catch (Throwable $e) {
                        $this->catat("{$id}: log router gagal disimpan ({$e->getMessage()})");
                    }
                    break;
            }
            unset($s['task_error'][$tugas]);
        } catch (Exception $e) {
            if (! str_starts_with($e->getMessage(), 'RouterOS error')) {
                throw $e;
            }
            if (($s['task_error'][$tugas] ?? null) !== $e->getMessage()) {
                $this->catat("{$id}: tugas {$tugas} ditolak router ({$e->getMessage()})");
            }
            $s['task_error'][$tugas] = $e->getMessage();
        }
    }

    private function ringkas(string $id, ?array $s): void
    {
        if (! $s) {
            $this->line("{$id}: tidak ada snapshot");
            return;
        }

        $cur = $s['ifaces'] ? $s['ifaces'][count($s['ifaces']) - 1]['c'] : [];
        $res = $s['resource']['data'] ?? [];
        $this->line(sprintf(
            '%s: %s | identity=%s cpu=%s%% uptime=%s | iface=%d (%s) | routes=%s addresses=%s | conn=%s%s',
            $id,
            $s['online'] ? 'online' : 'OFFLINE ' . $s['error'],
            $s['identity'] ?? '-',
            $res['cpu-load'] ?? '-',
            $res['uptime'] ?? '-',
            count($cur),
            implode(',', array_keys($cur)),
            isset($s['routes']) ? count($s['routes']['data']) : '-',
            isset($s['addresses']) ? count($s['addresses']['data']) : '-',
            isset($s['conn']) ? json_encode($s['conn']['data']) : '-',
            empty($s['task_error']) ? '' : ' | ditolak: ' . json_encode($s['task_error']),
        ));
    }

    private function jeda(float $detik): string
    {
        return (int) round($detik) . ' dtk';
    }

    private function catat(string $pesan): void
    {
        $this->line('[' . now()->format('Y-m-d H:i:s') . '] ' . $pesan);
    }
}
