<?php

namespace App\Services;

use App\Models\Router;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class MikrotikService
{
    public const CACHE_KEY = 'mikrotik.routers.v2';

    public const IFACE_PROPS = 'name,rx-byte,tx-byte,running';

    public const RESOURCE_PROPS = 'uptime,version,board-name,cpu-load,total-memory,free-memory,total-hdd-space,free-hdd-space';

    private const MAX_TIMEOUT = 8;

    private int $maxTimeout = self::MAX_TIMEOUT;

    public function __construct(private RouterGateway $gateway) {}

    public function routers(): array
    {
        $sealed = Cache::remember(self::CACHE_KEY, 300, fn () => Crypt::encryptString(serialize($this->bacaRouters())));

        try {
            $routers = unserialize(Crypt::decryptString((string) $sealed), ['allowed_classes' => false]);
        } catch (\Throwable) {
            Cache::forget(self::CACHE_KEY);

            return $this->bacaRouters();
        }

        return is_array($routers) ? $routers : [];
    }

    private function bacaRouters(): array
    {
        if (! Schema::hasTable('routers')) {
            return [];
        }

        return Router::orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (Router $r) => [$r->slug => $r->toConfig()])
            ->all();
    }

    public function routerConfig(string $id): array
    {
        $cfg = $this->routers()[$id] ?? null;
        abort_if(! $cfg, 404, 'Router tidak ditemukan.');
        return $cfg;
    }

    public static function forgetRouterCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('mikrotik.routers');
    }

    public function client(string $id): MikrotikClient
    {
        $c = $this->routerConfig($id);

        return $this->gateway->klien($id, $c, min(max(1, (int) ($c['timeout'] ?? 5)), $this->maxTimeout));
    }

    public function limitTimeout(int $detik): void
    {
        $this->maxTimeout = max(1, min($detik, self::MAX_TIMEOUT));
    }

    public function forgetClient(string $id): void
    {
        $this->gateway->lupakan($id);
    }

    public function q(string $id, string $cmd, array $params = []): array
    {
        try {
            return $this->client($id)->query($cmd, $params) ?: [];
        } catch (Exception $e) {
            $this->gateway->lupakan($id);
            throw $e;
        }
    }

    public function probe(string $host, string $user, string $pass, int $port = 8728, int $timeout = 5): array
    {
        $client = new MikrotikClient($host, $user, $pass, $port, $timeout);
        $res    = $client->query('/system/resource/print', ['=.proplist' => self::RESOURCE_PROPS])[0] ?? [];
        $idn    = $client->query('/system/identity/print')[0]  ?? [];

        return [
            'identity' => $idn['name']      ?? '-',
            'board'    => $res['board-name'] ?? '-',
            'version'  => $res['version']    ?? '-',
            'uptime'   => $res['uptime']     ?? '-',
        ];
    }

    public function interfaces(string $host, string $user, string $pass, int $port = 8728, int $timeout = 5): array
    {
        $client = new MikrotikClient($host, $user, $pass, $port, $timeout);

        return collect($client->query('/interface/print', ['=.proplist' => 'name']) ?: [])
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    public function isOnline(string $id): bool
    {
        try {
            $this->q($id, '/system/identity/print');
            return true;
        } catch (Exception) {
            return false;
        }
    }

    public function stats(string $id): array
    {
        $res = $this->q($id, '/system/resource/print', ['=.proplist' => self::RESOURCE_PROPS])[0] ?? [];
        $idn = $this->q($id, '/system/identity/print')[0] ?? [];

        return self::formatStats($res, $idn);
    }

    public static function formatStats(array $res, array $idn): array
    {
        $totalMem = (int) ($res['total-memory']   ?? 0);
        $freeMem  = (int) ($res['free-memory']     ?? 0);
        $usedMem  = $totalMem - $freeMem;

        $totalHdd = (int) ($res['total-hdd-space'] ?? 0);
        $freeHdd  = (int) ($res['free-hdd-space']  ?? 0);
        $usedHdd  = $totalHdd - $freeHdd;

        return [
            'identity'  => $idn['name']      ?? '-',
            'uptime'    => $res['uptime']     ?? '-',
            'version'   => $res['version']    ?? '-',
            'board'     => $res['board-name'] ?? '-',
            'cpu_load'  => (int) ($res['cpu-load'] ?? 0),
            'total_mem' => $totalMem,
            'used_mem'  => $usedMem,
            'mem_pct'   => $totalMem > 0 ? round($usedMem / $totalMem * 100) : 0,
            'total_hdd' => $totalHdd,
            'used_hdd'  => $usedHdd,
            'hdd_pct'   => $totalHdd > 0 ? round($usedHdd / $totalHdd * 100) : 0,
        ];
    }

    public function reboot(string $id): void
    {
        $this->client($id);

        try {
            $this->q($id, '/system/reboot');
        } catch (Exception $e) {
            if (preg_match('/permission|no such command/i', $e->getMessage())) {
                throw $e;
            }
        }
    }

    public static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => round($bytes / 1_073_741_824, 1) . ' GB',
            $bytes >= 1_048_576     => round($bytes / 1_048_576,     1) . ' MB',
            $bytes >= 1_024         => round($bytes / 1_024,         1) . ' KB',
            default                 => $bytes . ' B',
        };
    }
}
