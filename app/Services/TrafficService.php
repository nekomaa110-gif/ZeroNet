<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TrafficService
{
    public const TABLE = 'traffic_samples';

    public const KEEP_DAYS = 40;

    private const HISTORY_TTL = 300;

    private const HISTORY_LAG = 30;

    private const UMUR_SNAPSHOT = 90;

    public function __construct(
        private MikrotikService $mikrotik,
        private LoadBalanceService $lb,
        private RouterSnapshotService $snap,
    ) {}

    public function interfacesFor(string $id, bool $cachedOnly = false): array
    {
        $cfg = $this->mikrotik->routerConfig($id);
        if ($cachedOnly) {
            $det = $this->lb->cached($id);
        } else {
            try {
                $det = $this->lb->detect($id);
            } catch (Exception) {
                $det = $this->lb->known($id);
            }
        }

        $daftar = [];
        foreach ($det['wans'] ?? [] as $w) {
            if ($w['interface']) {
                $daftar[] = $w['interface'];
            }
        }
        if (($cfg['wan_interface'] ?? '') !== '') {
            $daftar[] = $cfg['wan_interface'];
        }

        return array_values(array_unique($daftar));
    }

    public function recordedInterfaces(string $id, int $days = 3): array
    {
        return Cache::remember("traffic.ifaces.{$id}.{$days}", self::HISTORY_TTL, fn () => DB::table(self::TABLE)
            ->where('router_slug', $id)
            ->where('sampled_at', '>=', now()->subDays($days))
            ->distinct()
            ->orderBy('interface')
            ->pluck('interface')
            ->all());
    }

    public function sample(string $id): array
    {
        $dariPoller = $this->sampleDariPoller($id);
        if ($dariPoller !== null) {
            return $dariPoller;
        }

        $nama   = $this->interfacesFor($id);
        $ifaces = collect($this->mikrotik->q($id, '/interface/print', ['=.proplist' => MikrotikService::IFACE_PROPS]))->keyBy('name');
        $res    = $this->mikrotik->q($id, '/system/resource/print', ['=.proplist' => MikrotikService::RESOURCE_PROPS])[0] ?? [];
        $uptime = self::uptimeSeconds($res['uptime'] ?? '');
        $waktu  = now();

        $rows = [];
        foreach ($nama as $n) {
            if (! isset($ifaces[$n])) {
                continue;
            }
            $rows[] = [
                'router_slug' => $id,
                'interface'   => $n,
                'rx_bytes'    => (int) ($ifaces[$n]['rx-byte'] ?? 0),
                'tx_bytes'    => (int) ($ifaces[$n]['tx-byte'] ?? 0),
                'uptime'      => $uptime,
                'sampled_at'  => $waktu,
            ];
        }

        return $rows;
    }

    private function sampleDariPoller(string $id): ?array
    {
        if (! $this->snap->pollerAlive()) {
            return null;
        }

        $s     = $this->snap->get($id) ?? [];
        $hist  = $s['ifaces'] ?? [];
        $akhir = $hist ? $hist[count($hist) - 1] : null;
        $res   = $s['resource'] ?? null;
        $kini  = microtime(true);

        if (! ($s['online'] ?? false) || ! $akhir || ! $res || $kini - $akhir['ts'] > self::UMUR_SNAPSHOT || $kini - $res['ts'] > self::UMUR_SNAPSHOT * 2) {
            return null;
        }

        $nama = $this->interfacesFor($id, true);
        if (! $nama) {
            return null;
        }

        $uptime = self::uptimeSeconds($res['data']['uptime'] ?? '') + (int) round($akhir['ts'] - $res['ts']);
        $waktu  = Carbon::createFromTimestamp($akhir['ts'], config('app.timezone'));
        $rows   = [];

        foreach ($nama as $n) {
            if (! isset($akhir['c'][$n])) {
                continue;
            }
            $rows[] = [
                'router_slug' => $id,
                'interface'   => $n,
                'rx_bytes'    => (int) $akhir['c'][$n]['rx'],
                'tx_bytes'    => (int) $akhir['c'][$n]['tx'],
                'uptime'      => max(0, $uptime),
                'sampled_at'  => $waktu,
            ];
        }

        return $rows ?: null;
    }

    public function live(string $id, float $window = 3.0): array
    {
        $this->snap->watch($id);

        if ($this->snap->pollerAlive()) {
            [$cur, $prev, $dt] = $this->snap->counters($this->snap->snapshot($id), $window);

            return $this->liveResult($this->interfacesFor($id, true), $cur, $prev, $dt) + ['source' => 'poller'];
        }

        $nama = $this->interfacesFor($id);
        $cur  = RouterSnapshotService::counterMap(
            $this->mikrotik->q($id, '/interface/print', ['=.proplist' => MikrotikService::IFACE_PROPS])
        );

        $now  = microtime(true);
        $key  = "traffic.live.c.{$id}";
        $prev = Cache::get($key);
        Cache::put($key, ['ts' => $now, 'c' => $cur], 120);
        $dt = $prev ? $now - $prev['ts'] : 0.0;

        return $this->liveResult($nama, $cur, $prev['c'] ?? null, $dt) + ['source' => 'direct'];
    }

    private function liveResult(array $nama, array $cur, ?array $prev, float $dt): array
    {
        $hasil = [];
        foreach ($nama as $n) {
            $c    = $cur[$n] ?? null;
            $o    = $prev[$n] ?? null;
            $down = null;
            $up   = null;
            if ($c && $o && $dt > 0 && $c['rx'] >= $o['rx'] && $c['tx'] >= $o['tx']) {
                $down = (int) round(($c['rx'] - $o['rx']) * 8 / $dt);
                $up   = (int) round(($c['tx'] - $o['tx']) * 8 / $dt);
            }
            $hasil[] = [
                'interface' => $n,
                'exists'    => $c !== null,
                'running'   => $c['running'] ?? false,
                'download'  => $down,
                'upload'    => $up,
            ];
        }

        return [
            'online'         => true,
            'measured'       => $dt > 0,
            'interfaces'     => $hasil,
            'total_download' => array_sum(array_map(fn ($x) => $x['download'] ?? 0, $hasil)),
            'total_upload'   => array_sum(array_map(fn ($x) => $x['upload'] ?? 0, $hasil)),
            'time'           => now()->format('H:i:s'),
        ];
    }

    public function commonStart(string $id, array $ifaces): ?string
    {
        $awal = DB::table(self::TABLE)
            ->where('router_slug', $id)
            ->whereIn('interface', $ifaces)
            ->groupBy('interface')
            ->selectRaw('interface, MIN(sampled_at) AS awal')
            ->pluck('awal', 'interface');

        return count($awal) === count($ifaces) ? $awal->max() : null;
    }

    public function history(string $id, int $days = 7, ?array $only = null, ?string $from = null): array
    {
        $slot  = intdiv(time() - self::HISTORY_LAG, self::HISTORY_TTL);
        $kunci = "traffic.hist.{$id}.{$days}." . md5(json_encode([$only, $from])) . ".{$slot}";

        return Cache::remember($kunci, self::HISTORY_TTL, fn () => $this->buildHistory($id, $days, $only, $from));
    }

    private function buildHistory(string $id, int $days, ?array $only, ?string $from): array
    {
        $mulai   = now()->startOfDay()->subDays($days - 1);
        $jamAwal = now()->subHours(23)->startOfHour();

        $rows = DB::table(self::TABLE)
            ->where('router_slug', $id)
            ->where('sampled_at', '>=', $mulai->copy()->subMinutes(15))
            ->when($from, fn ($q) => $q->where('sampled_at', '>=', $from))
            ->when($only, fn ($q) => $q->whereIn('interface', $only))
            ->orderBy('interface')
            ->orderBy('sampled_at')
            ->get(['interface', 'rx_bytes', 'tx_bytes', 'uptime', 'sampled_at']);

        $ifaces = [];
        $hari   = [];
        $jam    = [];
        $prev   = [];
        $sejak  = null;
        $slot   = 300;
        $tpAwal = (intdiv(now()->subDay()->timestamp, $slot) + 1) * $slot;
        $tpN    = intdiv(now()->timestamp - $tpAwal, $slot) + 1;
        $tp     = [];

        $mulaiTs   = $mulai->timestamp;
        $jamAwalTs = $jamAwal->timestamp;
        $sejakTs   = null;

        foreach ($rows as $r) {
            $ifaces[$r->interface] = true;
            $cur = [
                't'  => strtotime($r->sampled_at),
                'rx' => (int) $r->rx_bytes,
                'tx' => (int) $r->tx_bytes,
                'up' => (int) $r->uptime,
            ];
            $p = $prev[$r->interface] ?? null;
            $prev[$r->interface] = $cur;
            if (! $p) {
                continue;
            }

            $tengah = intdiv($cur['t'] + $p['t'], 2);
            if ($tengah < $mulaiTs) {
                continue;
            }

            $reboot = $cur['up'] < $p['up'] || $cur['rx'] < $p['rx'] || $cur['tx'] < $p['tx'];
            $rx = $reboot ? $cur['rx'] : $cur['rx'] - $p['rx'];
            $tx = $reboot ? $cur['tx'] : $cur['tx'] - $p['tx'];
            if ($sejakTs === null || $p['t'] < $sejakTs) {
                $sejakTs = $p['t'];
            }

            $dt   = $cur['t'] - $p['t'];
            $lama = $reboot ? min($dt, max(1, $cur['up'])) : $dt;
            $idx  = intdiv($cur['t'] - $tpAwal, $slot);
            if ($dt > 0 && $dt <= 900 && $idx >= 0 && $idx < $tpN) {
                $tp[$r->interface][$idx] = [(int) round($rx * 8 / $lama), (int) round($tx * 8 / $lama)];
            }

            $d = date('Y-m-d', $tengah);
            $hari[$d][$r->interface]['rx'] = ($hari[$d][$r->interface]['rx'] ?? 0) + $rx;
            $hari[$d][$r->interface]['tx'] = ($hari[$d][$r->interface]['tx'] ?? 0) + $tx;

            if ($tengah >= $jamAwalTs) {
                $h = date('Y-m-d H:00', $tengah);
                $jam[$h][$r->interface] = ($jam[$h][$r->interface] ?? 0) + $rx + $tx;
            }
        }

        $sejak  = $sejakTs === null ? null : Carbon::createFromTimestamp($sejakTs, config('app.timezone'));
        $ifaces = array_keys($ifaces);

        $daftarHari = [];
        $hariAwal   = $sejak ? $sejak->copy()->startOfDay()->max($mulai) : now()->addDay();
        for ($d = $hariAwal->copy(); $d->lte(now()); $d->addDay()) {
            $k = $d->format('Y-m-d');
            $daftarHari[] = [
                'date'    => $k,
                'partial' => $k === now()->format('Y-m-d') || ($sejak && $k === $sejak->format('Y-m-d') && $sejak->gt($d->copy()->startOfDay()->addMinutes(15))),
                'bytes'   => collect($ifaces)->mapWithKeys(fn ($i) => [$i => [
                    'rx' => $hari[$k][$i]['rx'] ?? 0,
                    'tx' => $hari[$k][$i]['tx'] ?? 0,
                ]])->all(),
            ];
        }

        $daftarJam = [];
        for ($h = $jamAwal->copy(); $h->lte(now()); $h->addHour()) {
            $k = $h->format('Y-m-d H:00');
            $daftarJam[] = [
                'hour'  => $h->format('H:00'),
                'bytes' => collect($ifaces)->mapWithKeys(fn ($i) => [$i => $jam[$k][$i] ?? 0])->all(),
            ];
        }

        $label = [];
        for ($k = 0; $k < $tpN; $k++) {
            $label[] = Carbon::createFromTimestamp($tpAwal + $k * $slot, config('app.timezone'))->format('H:i');
        }
        $seri = [];
        foreach ($ifaces as $i) {
            for ($k = 0; $k < $tpN; $k++) {
                $seri[$i]['rx'][] = $tp[$i][$k][0] ?? null;
                $seri[$i]['tx'][] = $tp[$i][$k][1] ?? null;
            }
        }

        return [
            'interfaces' => $ifaces,
            'since'      => $sejak?->format('d M Y H:i'),
            'days'       => $daftarHari,
            'hours'      => $daftarJam,
            'throughput' => ['labels' => $label, 'series' => $seri],
        ];
    }

    public static function uptimeSeconds(string $u): int
    {
        preg_match('/(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?/', $u, $m);

        return (int) ($m[1] ?? 0) * 604800 + (int) ($m[2] ?? 0) * 86400 + (int) ($m[3] ?? 0) * 3600
            + (int) ($m[4] ?? 0) * 60 + (int) ($m[5] ?? 0);
    }
}
