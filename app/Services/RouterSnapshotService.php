<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;

class RouterSnapshotService
{
    public const BEAT_KEY = 'mt.poller.beat';

    public const HIST_SECONDS = 15;

    private const WATCH_TTL = 30;

    private const ALIVE = 20;

    private const FRESH = 10;

    private const MAX_AGE = 90;

    private const PART_MAX_AGE = 120;

    private const SNAP_TTL = 600;

    private const HOTSPOT_TTL = 180;

    public function watch(string $id): void
    {
        Cache::put("mt.watch.{$id}", 1, self::WATCH_TTL);
    }

    public function watched(string $id): bool
    {
        return Cache::has("mt.watch.{$id}");
    }

    public function watchHotspot(string $id): void
    {
        Cache::put("mt.watch.hotspot.{$id}", 1, self::WATCH_TTL);
    }

    public function watchedHotspot(string $id): bool
    {
        return Cache::has("mt.watch.hotspot.{$id}");
    }

    public function watchLog(string $id): void
    {
        Cache::put("mt.watch.log.{$id}", 1, self::WATCH_TTL);
    }

    public function watchedLog(string $id): bool
    {
        return Cache::has("mt.watch.log.{$id}");
    }

    public function hotspot(string $id): array
    {
        return Cache::get("mt.hotspot.{$id}", []);
    }

    public function putHotspot(string $id, string $bagian, array $data): void
    {
        $h          = $this->hotspot($id);
        $h[$bagian] = ['ts' => microtime(true), 'data' => $data];
        Cache::put("mt.hotspot.{$id}", $h, self::HOTSPOT_TTL);
    }

    public function buangHotspot(string $id, string $bagian, string $itemId): void
    {
        $h = $this->hotspot($id);

        if (isset($h[$bagian]['data'])) {
            $h[$bagian]['data'] = array_values(array_filter($h[$bagian]['data'], fn ($r) => ($r['.id'] ?? null) !== $itemId));
            Cache::put("mt.hotspot.{$id}", $h, self::HOTSPOT_TTL);
        }
    }

    public function beat(): void
    {
        Cache::put(self::BEAT_KEY, microtime(true), 120);
    }

    public function pollerAlive(): bool
    {
        $t = Cache::get(self::BEAT_KEY);

        return $t && microtime(true) - $t < self::ALIVE;
    }

    public function get(string $id): ?array
    {
        return Cache::get("mt.snap.{$id}");
    }

    public function put(string $id, array $snap): void
    {
        Cache::put("mt.snap.{$id}", $snap, self::SNAP_TTL);
    }

    public function forget(string $id): void
    {
        Cache::forget("mt.snap.{$id}");
        Cache::forget("mt.hotspot.{$id}");
    }

    public function snapshot(string $id): array
    {
        $s = $this->get($id);
        if (! $s) {
            throw new Exception('Menunggu data poller.');
        }
        if (! ($s['online'] ?? false)) {
            throw new Exception($s['error'] ?? 'Router tidak dapat dihubungi.');
        }

        return $s;
    }

    public function counters(array $s, float $window): array
    {
        $hist = $s['ifaces'] ?? [];
        $cur  = $hist ? $hist[count($hist) - 1] : null;
        $umur = $cur ? microtime(true) - $cur['ts'] : INF;

        if ($umur > self::MAX_AGE) {
            throw new Exception('Data router kedaluwarsa.');
        }
        if ($umur > self::FRESH || count($hist) < 2) {
            return [$cur['c'], null, 0.0];
        }

        $prev = null;
        foreach (array_slice($hist, 0, -1) as $h) {
            if ($cur['ts'] - $h['ts'] >= $window - 0.5) {
                $prev = $h;
            }
        }
        $prev ??= $hist[0];

        $dt = $cur['ts'] - $prev['ts'];

        return $dt > 0 ? [$cur['c'], $prev['c'], $dt] : [$cur['c'], null, 0.0];
    }

    public function part(array $s, string $key): ?array
    {
        $p = $s[$key] ?? null;

        return $p && microtime(true) - $p['ts'] <= self::PART_MAX_AGE ? $p['data'] : null;
    }

    public function stats(string $id): array
    {
        $s   = $this->snapshot($id);
        $res = $this->part($s, 'resource') ?? throw new Exception('Menunggu data poller.');

        return MikrotikService::formatStats($res, ['name' => $s['identity'] ?? '-']);
    }

    public static function counterMap(array $rows): array
    {
        $c = [];
        foreach ($rows as $i) {
            $c[$i['name'] ?? ''] = [
                'rx'      => (int) ($i['rx-byte'] ?? 0),
                'tx'      => (int) ($i['tx-byte'] ?? 0),
                'running' => ($i['running'] ?? 'false') === 'true',
            ];
        }

        return $c;
    }
}
