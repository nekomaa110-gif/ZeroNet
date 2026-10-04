<?php

namespace App\Services;

use App\Exceptions\RouterSibuk;
use Exception;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class LoadBalanceConfigService
{
    public const CLASSIFIERS = ['both-addresses', 'both-addresses-and-ports', 'src-address', 'dst-address'];

    public const MAX_BUCKETS = 10;

    private const TEMPLATE_KEYS = [
        'chain', 'action', 'passthrough', 'in-interface', 'in-interface-list', 'out-interface', 'out-interface-list',
        'src-address', 'dst-address', 'src-address-list', 'dst-address-list', 'src-address-type', 'dst-address-type',
        'connection-state', 'connection-mark', 'protocol', 'src-port', 'dst-port', 'hotspot',
    ];

    public function __construct(
        private MikrotikService $mikrotik,
        private LoadBalanceService $lb,
        private RouterGateway $gateway,
    ) {}

    public function current(string $id): array
    {
        $s = $this->state($id);

        return [
            'balance' => $s['pcc'] ? [
                'editable'   => true,
                'enabled'    => $s['pcc']['enabled'],
                'classifier' => $s['pcc']['classifier'],
                'weights'    => $s['pcc']['weights'],
            ] : ['editable' => false, 'reason' => $s['reason']],
            'wans'      => array_map(fn ($w) => ['interface' => $w['interface'], 'gateway' => $w['gateway']], $s['det']['wans']),
            'radius'    => array_map(fn ($r) => [
                'address' => $r['address'],
                'main'    => $r['main'],
                'routes'  => array_map(fn ($x) => [
                    'interface' => $x['iface'],
                    'distance'  => (int) ($x['route']['distance'] ?? 0),
                    'active'    => ($x['route']['active'] ?? 'false') === 'true',
                    'comment'   => $x['route']['comment'] ?? '',
                ], $r['routes']),
            ], $s['radius']),
            'classifiers' => self::CLASSIFIERS,
            'max_buckets' => self::MAX_BUCKETS,
        ];
    }

    public function plan(string $id, array $input): array
    {
        $s   = $this->state($id);
        $ops = match ($input['type'] ?? '') {
            'balance' => $this->planBalance($s, $input),
            'radius'  => $this->planRadius($s, $input),
            default   => throw new InvalidArgumentException('Jenis perubahan tidak dikenal.'),
        };

        return [
            'ops'  => $ops,
            'text' => array_column($ops, 'text'),
            'hash' => sha1(json_encode(array_map(fn ($o) => [$o['cmd'], $o['params']], $ops))),
            'moves_tunnel' => (bool) array_filter($ops, fn ($o) => $o['moves_tunnel'] ?? false),
        ];
    }

    public function apply(string $id, array $input, string $hash): array
    {
        try {
            return $this->gateway->kunciTulis($id, fn () => $this->applyTerkunci($id, $input, $hash), ttl: 120);
        } catch (RouterSibuk) {
            return [
                'ok'      => false,
                'busy'    => true,
                'message' => 'Perubahan lain sedang diterapkan ke router ini. Tunggu sebentar, lalu ulangi pratinjau.',
            ];
        }
    }

    private function applyTerkunci(string $id, array $input, string $hash): array
    {
        $plan = $this->plan($id, $input);
        if ($plan['hash'] !== $hash) {
            return ['ok' => false, 'stale' => true, 'message' => 'Konfigurasi router berubah sejak pratinjau. Muat ulang pratinjau.'];
        }
        if (! $plan['ops']) {
            return ['ok' => true, 'done' => [], 'message' => 'Tidak ada perubahan.'];
        }

        $c    = $this->client($id);
        $done = [];
        $err  = null;
        foreach ($plan['ops'] as $i => $op) {
            try {
                $c->query($op['cmd'], $op['params']);
                $done[] = $op['text'];
            } catch (Exception $e) {
                $err = ['index' => $i, 'text' => $op['text'], 'message' => $e->getMessage(), 'after_move' => $this->movedBefore($plan['ops'], $i)];
                break;
            }
        }

        Cache::forget("lb.detect.{$id}");
        Cache::forget("lb.conn.{$id}");
        Cache::forget("router.down.{$id}");

        if ($err) {
            return [
                'ok'      => false,
                'done'    => $done,
                'error'   => $err,
                'message' => $err['after_move']
                    ? 'Koneksi ke router terputus setelah jalur dipindah. Ini wajar karena tunnel L2TP ikut pindah; cek lagi 1 sampai 2 menit lagi.'
                    : 'Perintah gagal: ' . $err['message'],
            ];
        }

        try {
            $sisa = $this->plan($id, $input)['text'];
        } catch (Exception $e) {
            return [
                'ok'      => $plan['moves_tunnel'],
                'done'    => $done,
                'pending' => true,
                'message' => $plan['moves_tunnel']
                    ? 'Perintah terkirim. Router belum bisa dibaca ulang karena tunnel sedang pindah jalur; cek lagi 1 sampai 2 menit lagi.'
                    : 'Perintah terkirim tapi router gagal dibaca ulang: ' . $e->getMessage(),
            ];
        }

        return [
            'ok'      => ! $sisa,
            'done'    => $done,
            'remain'  => $sisa,
            'message' => $sisa ? 'Sebagian perubahan belum terbaca di router.' : 'Perubahan diterapkan dan sudah dicek ulang di router.',
        ];
    }

    private function state(string $id): array
    {
        $c         = $this->client($id);
        $mangle    = $c->query('/ip/firewall/mangle/print') ?: [];
        $routes    = $c->query('/ip/route/print') ?: [];
        $addresses = $c->query('/ip/address/print') ?: [];
        $det       = $this->lb->analyze($mangle, $routes, $addresses);
        if (! $det) {
            throw new InvalidArgumentException('Router ini tidak terdeteksi memakai load balance.');
        }

        try {
            $radiusIp = array_values(array_unique(array_filter(
                array_map(fn ($r) => explode(',', $r['address'] ?? '')[0], $c->query('/radius/print') ?: [])
            )));
        } catch (Exception) {
            $radiusIp = [];
        }

        $pcc    = null;
        $reason = null;
        if ($det['method'] !== 'pcc') {
            $reason = 'Metode ' . strtoupper($det['method']) . ' belum bisa diubah dari panel.';
        } else {
            $pcc = $this->pccState($mangle, $det);
            if (! $pcc) {
                $reason = 'Rule PCC tidak bisa dipetakan ke WAN.';
            }
        }

        $radius = [];
        foreach ($radiusIp as $ip) {
            $calon = [];
            foreach ($routes as $r) {
                if (($r['dst-address'] ?? '') === $ip . '/32' && $this->lb->tableOf($r) === 'main' && ($r['disabled'] ?? 'false') !== 'true') {
                    $calon[] = ['iface' => $this->lb->interfaceOf($r, $routes, $addresses), 'route' => $r];
                }
            }
            usort($calon, fn ($a, $b) => (int) ($a['route']['distance'] ?? 0) <=> (int) ($b['route']['distance'] ?? 0));
            $radius[] = [
                'address' => $ip,
                'main'    => $calon[0]['iface'] ?? null,
                'via'     => $this->lb->hostPath($ip, $routes, $addresses)['via'],
                'routes'  => $calon,
            ];
        }

        return ['det' => $det, 'mangle' => $mangle, 'addresses' => $addresses, 'pcc' => $pcc, 'reason' => $reason, 'radius' => $radius];
    }

    private function pccState(array $mangle, array $det): ?array
    {
        $rules = [];
        foreach ($mangle as $pos => $m) {
            $cls  = $m['per-connection-classifier'] ?? '';
            $aksi = $m['action'] ?? '';
            if ($cls === '' || ! in_array($aksi, ['mark-connection', 'mark-routing'], true)) {
                continue;
            }
            $markKey = $aksi === 'mark-connection' ? 'new-connection-mark' : 'new-routing-mark';
            $wan     = null;
            foreach ($det['wans'] as $w) {
                $daftar = $aksi === 'mark-connection' ? $w['conn_marks'] : $w['tables'];
                if (in_array($m[$markKey] ?? '', $daftar, true)) {
                    $wan = $w['interface'];
                }
            }
            if ($wan === null) {
                continue;
            }
            $rules[] = ['pos' => $pos, 'row' => $m, 'wan' => $wan, 'mark_key' => $markKey];
        }

        if (! $rules) {
            return null;
        }

        $markOf = [];
        foreach ($rules as $r) {
            $markOf[$r['wan']] ??= $r['row'][$r['mark_key']];
        }
        foreach ($det['wans'] as $w) {
            if (! isset($markOf[$w['interface']])) {
                $markOf[$w['interface']] = $rules[0]['mark_key'] === 'new-connection-mark'
                    ? ($w['conn_marks'][0] ?? null)
                    : ($w['tables'][0] ?? null);
            }
        }

        $weights = [];
        foreach ($det['wans'] as $w) {
            $weights[$w['interface']] = count(array_filter($rules, fn ($r) => $r['wan'] === $w['interface']));
        }

        return [
            'rules'      => $rules,
            'mark_key'   => $rules[0]['mark_key'],
            'mark_of'    => $markOf,
            'classifier' => explode(':', $rules[0]['row']['per-connection-classifier'])[0],
            'enabled'    => (bool) array_filter($rules, fn ($r) => ($r['row']['disabled'] ?? 'false') !== 'true'),
            'weights'    => $weights,
        ];
    }

    private function planBalance(array $s, array $input): array
    {
        $pcc = $s['pcc'];
        if (! $pcc) {
            throw new InvalidArgumentException($s['reason'] ?? 'Load balance tidak bisa diubah.');
        }

        $cls = (string) ($input['classifier'] ?? '');
        if (! in_array($cls, self::CLASSIFIERS, true)) {
            throw new InvalidArgumentException('Classifier tidak dikenal.');
        }
        $enabled = filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOL);

        $weights = [];
        foreach ($pcc['mark_of'] as $iface => $mark) {
            if (! $mark) {
                continue;
            }
            $w = (int) ($input['weights'][$iface] ?? 0);
            if ($w < 0 || $w > self::MAX_BUCKETS) {
                throw new InvalidArgumentException("Bobot {$iface} harus 0 sampai " . self::MAX_BUCKETS . '.');
            }
            $weights[$iface] = $w;
        }
        $g = array_reduce(array_filter($weights), fn ($a, $b) => $this->gcd($a, $b), 0);
        if ($g < 1 || count(array_filter($weights)) < 1) {
            throw new InvalidArgumentException('Minimal satu WAN harus punya bobot lebih dari 0.');
        }
        $weights = array_map(fn ($w) => intdiv($w, $g), $weights);
        if (array_sum($weights) < 2) {
            $weights = array_map(fn ($w) => $w * 2, $weights);
        }
        $total = array_sum($weights);
        if ($total > self::MAX_BUCKETS) {
            throw new InvalidArgumentException('Total bagian setelah disederhanakan maksimal ' . self::MAX_BUCKETS . '.');
        }

        $bucket = [];
        foreach ($weights as $iface => $w) {
            for ($i = 0; $i < $w; $i++) {
                $bucket[] = $iface;
            }
        }

        $rules   = $pcc['rules'];
        $markKey = $pcc['mark_key'];
        $ops     = [];
        $pakai   = min(count($rules), $total);

        for ($i = 0; $i < $pakai; $i++) {
            $row  = $rules[$i]['row'];
            $mau  = [
                'per-connection-classifier' => "{$cls}:{$total}/{$i}",
                $markKey                     => $pcc['mark_of'][$bucket[$i]],
                'disabled'                   => $enabled ? 'false' : 'true',
            ];
            $ubah = [];
            foreach ($mau as $k => $v) {
                if (($row[$k] ?? ($k === 'disabled' ? 'false' : '')) !== $v) {
                    $ubah[$k] = $k === 'disabled' ? ($v === 'true' ? 'yes' : 'no') : $v;
                }
            }
            if ($ubah) {
                $ops[] = $this->op('/ip/firewall/mangle/set', ['=numbers' => $row['.id']] + $this->prefix($ubah), 'set', $this->label($row), $ubah);
            }
        }

        if ($total > count($rules)) {
            $akhir    = end($rules);
            $template = [];
            foreach (self::TEMPLATE_KEYS as $k) {
                $v = $akhir['row'][$k] ?? '';
                if ($v !== '') {
                    $template[$k] = $k === 'passthrough' ? ($v === 'true' ? 'yes' : 'no') : $v;
                }
            }
            $sesudah = $s['mangle'][$akhir['pos'] + 1]['.id'] ?? null;
            $nomor   = $this->nextNumber($rules);
            for ($i = count($rules); $i < $total; $i++) {
                $baru = $template + [
                    'per-connection-classifier' => "{$cls}:{$total}/{$i}",
                    $markKey                     => $pcc['mark_of'][$bucket[$i]],
                    'disabled'                   => $enabled ? 'no' : 'yes',
                    'comment'                    => 'LB-pcc' . $nomor++,
                ];
                $params = $this->prefix($baru);
                if ($sesudah) {
                    $params['=place-before'] = $sesudah;
                }
                $ops[] = $this->op('/ip/firewall/mangle/add', $params, 'add', null, $baru, $sesudah ? $this->label($s['mangle'][$akhir['pos'] + 1]) : null);
            }
        }

        for ($i = $total; $i < count($rules); $i++) {
            $row   = $rules[$i]['row'];
            $ops[] = $this->op('/ip/firewall/mangle/remove', ['=numbers' => $row['.id']], 'remove', $this->label($row), []);
        }

        return $ops;
    }

    private function planRadius(array $s, array $input): array
    {
        $main = (string) ($input['main'] ?? '');
        $wans = array_column($s['det']['wans'], 'interface');
        if (! in_array($main, $wans, true)) {
            throw new InvalidArgumentException('WAN main route tidak dikenal.');
        }
        if (! $s['radius']) {
            throw new InvalidArgumentException('Router tidak memakai server RADIUS.');
        }

        $ops = [];
        foreach ($s['radius'] as $rad) {
            if ($rad['routes']) {
                $ops = [...$ops, ...$this->reorderRadius($rad, $main)];
            } else {
                $ops = [...$ops, ...$this->createRadius($s, $rad['address'], $main, $rad['via'])];
            }
        }

        return $ops;
    }

    private function reorderRadius(array $rad, string $main): array
    {
        $routes = $rad['routes'];
        $target = array_values(array_filter($routes, fn ($x) => $x['iface'] === $main));
        if (! $target) {
            throw new InvalidArgumentException("Belum ada rute ke {$rad['address']} lewat {$main}.");
        }

        $urutan = [$target[0], ...array_values(array_filter($routes, fn ($x) => $x !== $target[0]))];
        $mau    = [];
        foreach ($urutan as $n => $x) {
            $mau[$x['route']['.id']] = $n + 1;
        }

        $ops     = [];
        $primer  = $routes[0];
        $pindah  = $primer['iface'] !== $main;
        if ($pindah) {
            $ops[] = $this->op('/ip/route/set', ['=numbers' => $primer['route']['.id'], '=distance' => (string) (count($routes) + 1)],
                'set', $this->label($primer['route']), ['distance' => count($routes) + 1], null, $rad['via'] !== $main);
        }
        foreach ($urutan as $x) {
            $id  = $x['route']['.id'];
            $cur = (int) ($x['route']['distance'] ?? 0);
            if ($pindah && $id === $primer['route']['.id']) {
                $cur = count($routes) + 1;
            }
            if ($cur !== $mau[$id]) {
                $ops[] = $this->op('/ip/route/set', ['=numbers' => $id, '=distance' => (string) $mau[$id]],
                    'set', $this->label($x['route']), ['distance' => $mau[$id]]);
            }
        }

        return $ops;
    }

    private function createRadius(array $s, string $ip, string $main, ?string $via): array
    {
        $wans = $s['det']['wans'];
        usort($wans, fn ($a, $b) => ($a['interface'] === $main ? 1 : 0) <=> ($b['interface'] === $main ? 1 : 0));

        $ops   = [];
        $jarak = 2;
        foreach ($wans as $w) {
            $gw = $w['gateway'];
            if (! filter_var($gw, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException("Gateway {$w['interface']} tidak bisa dipakai untuk rute RADIUS.");
            }
            $utama = $w['interface'] === $main;
            $d     = $utama ? 1 : $jarak++;
            $p = [
                'dst-address'   => $ip . '/32',
                'gateway'       => $gw,
                'distance'      => (string) $d,
                'check-gateway' => 'ping',
                'comment'       => 'LB-radius-' . $w['interface'],
            ];
            if (! $this->connected($gw, $s['addresses'])) {
                $p['target-scope'] = '11';
            }
            $ops[] = $this->op('/ip/route/add', $this->prefix($p), 'add', null, $p, null, $utama && $via !== $main);
        }

        return $ops;
    }

    private function op(string $cmd, array $params, string $verb, ?string $target, array $show, ?string $before = null, bool $movesTunnel = false): array
    {
        $menu  = '/' . str_replace('/', ' ', ltrim(dirname($cmd), '/'));
        $parts = [];
        foreach ($show as $k => $v) {
            $parts[] = $k . '=' . (preg_match('/[\s"]/', (string) $v) || $v === '' ? '"' . addcslashes((string) $v, '"') . '"' : $v);
        }
        if ($before) {
            $parts[] = 'place-before=' . $before;
        }
        $text = trim($menu . ' ' . $verb . ($target ? ' ' . $target : '') . ' ' . implode(' ', $parts));

        return ['cmd' => $cmd, 'params' => $params, 'text' => $text, 'moves_tunnel' => $movesTunnel];
    }

    private function prefix(array $p): array
    {
        $out = [];
        foreach ($p as $k => $v) {
            $out['=' . $k] = (string) $v;
        }

        return $out;
    }

    private function label(array $row): string
    {
        $km = $row['comment'] ?? '';

        return $km !== '' ? '[find comment="' . $km . '"]' : ($row['.id'] ?? '?');
    }

    private function nextNumber(array $rules): int
    {
        $maks = 0;
        foreach ($rules as $r) {
            if (preg_match('/(\d+)\s*$/', $r['row']['comment'] ?? '', $m)) {
                $maks = max($maks, (int) $m[1]);
            }
        }

        return max($maks, count($rules)) + 1;
    }

    private function movedBefore(array $ops, int $i): bool
    {
        for ($k = 0; $k <= $i; $k++) {
            if ($ops[$k]['moves_tunnel'] ?? false) {
                return true;
            }
        }

        return false;
    }

    private function connected(string $ip, array $addresses): bool
    {
        foreach ($addresses as $a) {
            if (($a['disabled'] ?? 'false') === 'true' || ! str_contains($a['address'] ?? '', '/')) {
                continue;
            }
            [$net, $mask] = explode('/', $a['address']);
            $bits = 32 - (int) $mask;
            if ($bits < 32 && (ip2long($net) >> $bits) === (ip2long($ip) >> $bits)) {
                return true;
            }
        }

        return false;
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }

    private function client(string $id): MikrotikClient
    {
        $c = $this->mikrotik->routerConfig($id);

        return $this->gateway->klien($id, $c, min(max(1, (int) ($c['timeout'] ?? 5)), 10));
    }
}
