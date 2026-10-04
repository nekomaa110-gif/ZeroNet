<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;

class LoadBalanceService
{
    private const DETECT_TTL = 60;

    private const CONN_TTL = 30;

    private const WINDOW = 5.0;

    public function __construct(
        private MikrotikService $mikrotik,
        private RouterSnapshotService $snap,
    ) {}

    public function detect(string $id, bool $fresh = false): ?array
    {
        $key = "lb.detect.{$id}";
        if (! $fresh && Cache::has($key)) {
            return Cache::get($key) ?: null;
        }

        $hasil = $this->analyze(
            $this->mikrotik->q($id, '/ip/firewall/mangle/print'),
            $this->mikrotik->q($id, '/ip/route/print'),
            $this->mikrotik->q($id, '/ip/address/print'),
        );

        if ($hasil) {
            try {
                $hasil['radius'] = array_values(array_unique(array_filter(
                    array_map(fn ($r) => explode(',', $r['address'] ?? '')[0], $this->mikrotik->q($id, '/radius/print'))
                )));
            } catch (Exception) {
                $hasil['radius'] = [];
            }
        }

        Cache::put($key, $hasil ?? false, self::DETECT_TTL);

        if ($hasil) {
            Cache::forever("lb.known.{$id}", $hasil + ['detected_at' => now()->toIso8601String()]);
        } else {
            Cache::forget("lb.known.{$id}");
        }

        return $hasil;
    }

    public function known(string $id): ?array
    {
        return Cache::get("lb.known.{$id}");
    }

    public function cached(string $id): ?array
    {
        $key = "lb.detect.{$id}";

        return Cache::has($key) ? (Cache::get($key) ?: null) : $this->known($id);
    }

    public function analyze(array $mangle, array $routes, array $addresses): ?array
    {
        return $this->analyzeClassifier($mangle, $routes, $addresses)
            ?? $this->analyzeEcmp($routes, $addresses);
    }

    public function live(string $id): array
    {
        $this->snap->watch($id);

        if ($this->snap->pollerAlive()) {
            $det = $this->cached($id);
            if (! $det) {
                return ['online' => true, 'lb' => false, 'source' => 'poller'];
            }

            $s = $this->snap->snapshot($id);
            [$cur, $prev, $dt] = $this->snap->counters($s, self::WINDOW);
            $routes    = $this->snap->part($s, 'routes');
            $addresses = $this->snap->part($s, 'addresses');
            if ($routes === null || $addresses === null) {
                throw new Exception('Menunggu data poller.');
            }
            $cpu  = (int) ($this->snap->part($s, 'resource')['cpu-load'] ?? 0);
            $conn = $det['method'] === 'ecmp' ? null : $this->snap->part($s, 'conn');

            return $this->liveResult($det, $cur, $prev, $dt, $routes, $addresses, $cpu, $conn) + ['source' => 'poller'];
        }

        $det = $this->detect($id);
        if (! $det) {
            return ['online' => true, 'lb' => false, 'source' => 'direct'];
        }

        $cur       = RouterSnapshotService::counterMap(
            $this->mikrotik->q($id, '/interface/print', ['=.proplist' => MikrotikService::IFACE_PROPS])
        );
        $routes    = $this->mikrotik->q($id, '/ip/route/print');
        $addresses = $this->mikrotik->q($id, '/ip/address/print');
        $res       = $this->mikrotik->q($id, '/system/resource/print', ['=.proplist' => MikrotikService::RESOURCE_PROPS])[0] ?? [];

        $now  = microtime(true);
        $key  = "lb.traffic.{$id}";
        $prev = Cache::get($key);
        Cache::put($key, ['ts' => $now, 'c' => $cur], 120);
        $dt = $prev ? $now - $prev['ts'] : 0.0;

        $conn = $det['method'] === 'ecmp' ? null : $this->connectionCounts($id);

        return $this->liveResult($det, $cur, $prev['c'] ?? null, $dt, $routes, $addresses, (int) ($res['cpu-load'] ?? 0), $conn)
            + ['source' => 'direct'];
    }

    private function liveResult(array $det, array $counter, ?array $prev, float $dt, array $routes, array $addresses, int $cpu, ?array $conn): array
    {
        $wans = [];
        foreach ($det['wans'] as $w) {
            $name = $w['interface'];
            $cur  = $counter[$name] ?? null;
            $old  = $prev[$name] ?? null;
            $down = 0;
            $up   = 0;
            if ($cur && $old && $dt > 0 && $cur['rx'] >= $old['rx'] && $cur['tx'] >= $old['tx']) {
                $down = (int) round(($cur['rx'] - $old['rx']) * 8 / $dt);
                $up   = (int) round(($cur['tx'] - $old['tx']) * 8 / $dt);
            }

            $state = $det['method'] === 'ecmp'
                ? $this->ecmpState($w, $routes)
                : $this->tableState($w, $routes, $addresses);

            $wans[] = [
                'interface'  => $name,
                'label'      => $w['label'],
                'gateway'    => $w['gateway'],
                'target_pct' => $w['target_pct'],
                'running'    => $cur['running'] ?? false,
                'download'   => $down,
                'upload'     => $up,
                'conn'       => $conn === null ? null : array_sum(array_map(fn ($m) => $conn[$m] ?? 0, $w['conn_marks'])),
                'state'      => $state['state'],
                'via'        => $state['via'],
            ];
        }

        $totDown = array_sum(array_column($wans, 'download'));
        $totUp   = array_sum(array_column($wans, 'upload'));
        $totConn = $conn === null ? 0 : array_sum(array_column($wans, 'conn'));

        foreach ($wans as &$w) {
            $w['share_down'] = $totDown > 0 ? round($w['download'] * 100 / $totDown, 1) : null;
            $w['share_up']   = $totUp > 0 ? round($w['upload'] * 100 / $totUp, 1) : null;
            $w['share_conn'] = $totConn > 0 ? round($w['conn'] * 100 / $totConn, 1) : null;
        }
        unset($w);

        return [
            'online'         => true,
            'lb'             => true,
            'enabled'        => $det['enabled'],
            'measured'       => $dt > 0,
            'cpu'            => $cpu,
            'total_download' => $totDown,
            'total_upload'   => $totUp,
            'wans'           => $wans,
            'radius'         => array_map(fn ($ip) => $this->hostPath($ip, $routes, $addresses), $det['radius'] ?? []),
            'time'           => now()->format('H:i:s'),
        ];
    }

    public function hostPath(string $ip, array $routes, array $addresses): array
    {
        $calon = array_values(array_filter($routes, fn ($r) => ($r['dst-address'] ?? '') === $ip . '/32'
            && $this->tableOf($r) === 'main' && ($r['disabled'] ?? 'false') !== 'true'));
        $khusus = (bool) $calon;
        if (! $calon) {
            $calon = $this->defaultRoutesByTable($routes)['main'] ?? [];
        }
        usort($calon, fn ($a, $b) => (int) ($a['distance'] ?? 0) <=> (int) ($b['distance'] ?? 0));

        $aktif  = null;
        $semua  = [];
        foreach ($calon as $r) {
            $iface = $this->interfaceOf($r, $routes, $addresses);
            if ($iface && ! in_array($iface, $semua, true)) {
                $semua[] = $iface;
            }
            if (! $aktif && ($r['active'] ?? 'false') === 'true') {
                $aktif = ['iface' => $iface, 'route' => $r];
            }
        }

        $via     = $aktif['iface'] ?? null;
        $utama   = $calon ? $this->interfaceOf($calon[0], $routes, $addresses) : null;
        $dicek   = $calon && ($calon[0]['check-gateway'] ?? '') !== '';
        $cadang  = array_values(array_filter($semua, fn ($i) => $i !== $via));

        return [
            'address'   => $ip,
            'via'       => $via,
            'backup'    => $cadang,
            'dedicated' => $khusus,
            'protected' => $dicek && count($semua) >= 2,
            'on_backup' => $via !== null && $utama !== null && $via !== $utama,
        ];
    }

    private function analyzeClassifier(array $mangle, array $routes, array $addresses): ?array
    {
        $routingOfConn = [];
        foreach ($mangle as $m) {
            $cm = $m['connection-mark'] ?? '';
            if (($m['action'] ?? '') === 'mark-routing' && $cm !== '' && $cm !== 'no-mark' && ($m['new-routing-mark'] ?? '') !== '') {
                $routingOfConn[$cm] ??= $m['new-routing-mark'];
            }
        }

        $tables     = [];
        $jenis      = null;
        $penyebut   = 0;
        $adaAktif   = false;
        foreach ($mangle as $m) {
            $pcc = $m['per-connection-classifier'] ?? '';
            $nth = $m['nth'] ?? '';
            if ($pcc === '' && $nth === '') {
                continue;
            }

            $aksi     = $m['action'] ?? '';
            $connMark = null;
            if ($aksi === 'mark-connection') {
                $connMark = $m['new-connection-mark'] ?? '';
                $tabel    = $routingOfConn[$connMark] ?? null;
            } elseif ($aksi === 'mark-routing') {
                $tabel = $m['new-routing-mark'] ?? null;
            } else {
                continue;
            }
            if (! $tabel) {
                continue;
            }

            if ($pcc !== '') {
                [$j, $pecahan] = array_pad(explode(':', $pcc, 2), 2, '');
                $n = (int) explode('/', $pecahan)[0];
            } else {
                $j = 'nth';
                $n = (int) explode(',', $nth)[0];
            }
            $jenis    ??= $j;
            $penyebut = max($penyebut, $n);

            $aktif    = ($m['disabled'] ?? 'false') !== 'true';
            $adaAktif = $adaAktif || $aktif;

            $tables[$tabel] ??= ['conn_marks' => [], 'buckets' => 0];
            $tables[$tabel]['buckets']++;
            if ($connMark) {
                $tables[$tabel]['conn_marks'][$connMark] = true;
            }
        }

        $defaults = $this->defaultRoutesByTable($routes);
        $tables   = array_filter($tables, fn ($t, $nama) => isset($defaults[$nama]), ARRAY_FILTER_USE_BOTH);
        if (count($tables) < 2) {
            return null;
        }

        $totalBucket = array_sum(array_column($tables, 'buckets'));
        $wans        = [];
        foreach ($tables as $nama => $t) {
            $utama = $defaults[$nama][0];
            $iface = $this->interfaceOf($utama, $routes, $addresses);
            $kunci = $iface ?? $nama;

            $wans[$kunci] ??= [
                'interface'  => $iface,
                'label'      => $iface ?? $nama,
                'gateway'    => $utama['gateway'] ?? '',
                'tables'     => [],
                'conn_marks' => [],
                'target_pct' => 0,
            ];
            $wans[$kunci]['tables'][]   = $nama;
            $wans[$kunci]['conn_marks'] = array_values(array_unique([...$wans[$kunci]['conn_marks'], ...array_keys($t['conn_marks'])]));
            $wans[$kunci]['target_pct'] += round($t['buckets'] * 100 / max(1, $totalBucket), 1);
        }

        if (count($wans) < 2) {
            return null;
        }

        return [
            'method'      => $jenis === 'nth' ? 'nth' : 'pcc',
            'classifier'  => $jenis === 'nth' ? null : $jenis,
            'denominator' => $penyebut,
            'enabled'     => $adaAktif,
            'wans'        => array_values($wans),
        ];
    }

    private function analyzeEcmp(array $routes, array $addresses): ?array
    {
        $main = array_values(array_filter($this->defaultRoutesByTable($routes)['main'] ?? [], fn ($r) => ($r['active'] ?? 'false') === 'true'));
        if (! $main) {
            return null;
        }

        $jarak = (int) ($main[0]['distance'] ?? 0);
        $gw    = [];
        foreach ($main as $r) {
            if ((int) ($r['distance'] ?? 0) !== $jarak) {
                continue;
            }
            $status = explode(',', $r['gateway-status'] ?? '');
            foreach (explode(',', $r['gateway'] ?? '') as $i => $g) {
                if ($g === '') {
                    continue;
                }
                $iface = $this->ifaceFromStatus($status[$i] ?? '')
                    ?? $this->interfaceOf(['gateway' => $g], $routes, $addresses);
                $kunci = $iface ?? $g;
                $gw[$kunci] ??= ['interface' => $iface, 'label' => $iface ?? $g, 'gateway' => $g, 'count' => 0];
                $gw[$kunci]['count']++;
            }
        }

        if (count($gw) < 2) {
            return null;
        }

        $total = array_sum(array_column($gw, 'count'));

        return [
            'method'      => 'ecmp',
            'classifier'  => null,
            'denominator' => $total,
            'enabled'     => true,
            'wans'        => array_values(array_map(fn ($g) => [
                'interface'  => $g['interface'],
                'label'      => $g['label'],
                'gateway'    => $g['gateway'],
                'tables'     => ['main'],
                'conn_marks' => [],
                'target_pct' => round($g['count'] * 100 / $total, 1),
            ], $gw)),
        ];
    }

    private function defaultRoutesByTable(array $routes): array
    {
        $hasil = [];
        foreach ($routes as $r) {
            if (($r['dst-address'] ?? '') !== '0.0.0.0/0' || ($r['disabled'] ?? 'false') === 'true') {
                continue;
            }
            $hasil[$this->tableOf($r)][] = $r;
        }
        foreach ($hasil as &$daftar) {
            usort($daftar, fn ($a, $b) => (int) ($a['distance'] ?? 0) <=> (int) ($b['distance'] ?? 0));
        }
        unset($daftar);

        return $hasil;
    }

    public function tableOf(array $r): string
    {
        if (($r['routing-table'] ?? '') !== '') {
            return $r['routing-table'];
        }

        return ($r['routing-mark'] ?? '') !== '' ? $r['routing-mark'] : 'main';
    }

    private function tableState(array $wan, array $routes, array $addresses): array
    {
        $defaults = $this->defaultRoutesByTable($routes);
        $via      = null;
        $adaAktif = false;
        foreach ($wan['tables'] as $t) {
            foreach ($defaults[$t] ?? [] as $r) {
                if (($r['active'] ?? 'false') !== 'true') {
                    continue;
                }
                $adaAktif = true;
                $iface    = $this->interfaceOf($r, $routes, $addresses);
                if ($iface !== $wan['interface']) {
                    $via = $iface;
                }
            }
        }

        if (! $adaAktif) {
            return ['state' => 'down', 'via' => null];
        }

        return $via ? ['state' => 'failover', 'via' => $via] : ['state' => 'normal', 'via' => null];
    }

    private function ecmpState(array $wan, array $routes): array
    {
        foreach ($this->defaultRoutesByTable($routes)['main'] ?? [] as $r) {
            if (($r['active'] ?? 'false') !== 'true') {
                continue;
            }
            foreach (explode(',', $r['gateway-status'] ?? '') as $s) {
                if (str_starts_with(trim($s), $wan['gateway'] . ' ')) {
                    return ['state' => str_contains($s, 'unreachable') ? 'down' : 'normal', 'via' => null];
                }
            }
            if (str_contains($r['immediate-gw'] ?? '', '%' . $wan['interface'])) {
                return ['state' => 'normal', 'via' => null];
            }
        }

        return ['state' => 'down', 'via' => null];
    }

    public function interfaceOf(array $route, array $routes, array $addresses, int $depth = 0): ?string
    {
        $imm = $route['immediate-gw'] ?? '';
        if (str_contains($imm, '%')) {
            return substr($imm, strrpos($imm, '%') + 1);
        }

        $dariStatus = $this->ifaceFromStatus(explode(',', $route['gateway-status'] ?? '')[0]);
        if ($dariStatus) {
            return $dariStatus;
        }

        $gw = explode(',', $route['gateway'] ?? '')[0];
        if ($gw === '') {
            return null;
        }
        if (str_contains($gw, '%')) {
            return substr($gw, strrpos($gw, '%') + 1);
        }
        if (! filter_var($gw, FILTER_VALIDATE_IP)) {
            return $gw;
        }

        foreach ($addresses as $a) {
            if (($a['disabled'] ?? 'false') === 'true' || ! str_contains($a['address'] ?? '', '/')) {
                continue;
            }
            [$ip, $mask] = explode('/', $a['address']);
            $bits = 32 - (int) $mask;
            if ((ip2long($ip) >> $bits) === (ip2long($gw) >> $bits)) {
                return $a['interface'] ?? null;
            }
        }

        if ($depth >= 3) {
            return null;
        }
        foreach ($routes as $r) {
            if (($r['dst-address'] ?? '') === $gw . '/32' && $this->tableOf($r) === 'main' && ($r['disabled'] ?? 'false') !== 'true') {
                return $this->interfaceOf($r, $routes, $addresses, $depth + 1);
            }
        }

        return null;
    }

    private function ifaceFromStatus(string $status): ?string
    {
        $status = trim($status);
        if ($status === '' || str_contains($status, 'unreachable')) {
            return null;
        }
        $token = preg_split('/\s+/', $status);
        $akhir = end($token);
        if (! $akhir || filter_var($akhir, FILTER_VALIDATE_IP) || in_array($akhir, ['reachable', 'recursive', 'via'], true)) {
            return null;
        }

        return $akhir;
    }

    public function countConnections(string $id): array
    {
        $n = [];
        foreach ($this->mikrotik->q($id, '/ip/firewall/connection/print', ['=.proplist' => 'connection-mark']) as $k) {
            $m = $k['connection-mark'] ?? '';
            $n[$m] = ($n[$m] ?? 0) + 1;
        }

        return $n;
    }

    private function connectionCounts(string $id): ?array
    {
        $key = "lb.conn.{$id}";
        if (Cache::has($key)) {
            return Cache::get($key);
        }

        try {
            $n = $this->countConnections($id);
        } catch (Exception) {
            return null;
        }

        Cache::put($key, $n, self::CONN_TTL);

        return $n;
    }
}
