<?php

namespace App\Services;

use App\Exceptions\RouterSibuk;
use Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RouterGateway
{
    public const WEB = 'web';

    public const ANTREAN = 'antrean';

    public const POLLER = 'poller';

    private array $pool = [];

    private ?string $konteks = null;

    public function aturKonteks(string $konteks): void
    {
        $this->konteks = $konteks;
    }

    public function konteks(): string
    {
        return $this->konteks ?? (app()->runningInConsole() ? self::ANTREAN : self::WEB);
    }

    public function klien(string $slug, array $cfg, ?int $timeout = null): MikrotikClient
    {
        $timeout = max(1, $timeout ?? (int) config('services.mikrotik.timeout.' . $this->konteks(), 8));
        $sidik   = md5($cfg['host'] . '|' . $cfg['port'] . '|' . $cfg['user'] . '|' . $cfg['pass']);
        $ada     = $this->pool[$slug] ?? null;

        $batasIdle = $this->konteks() === self::POLLER ? INF : (int) config('services.mikrotik.idle_ulang', 20);

        if ($ada && $ada['sidik'] === $sidik && $ada['klien']->masihTersambung() && $ada['klien']->idle() < $batasIdle) {
            $ada['klien']->aturTimeout($timeout);

            return $ada['klien'];
        }

        $this->lupakan($slug);

        if ($this->konteks() !== self::POLLER && Cache::has("router.down.{$slug}")) {
            throw new Exception('Router tidak dapat dihubungi (dicoba lagi dalam beberapa detik).');
        }

        try {
            $klien = new MikrotikClient($cfg['host'], $cfg['user'], $cfg['pass'], (int) $cfg['port'], $timeout);
        } catch (Exception $e) {
            Cache::put("router.down.{$slug}", true, (int) config('services.mikrotik.down_ttl', 15));
            throw $e;
        }

        Cache::forget("router.down.{$slug}");
        $this->pool[$slug] = ['klien' => $klien, 'sidik' => $sidik];

        return $klien;
    }

    public function jalankan(string $slug, array $cfg, callable $kerja, ?int $timeout = null): mixed
    {
        try {
            return $kerja($this->klien($slug, $cfg, $timeout));
        } catch (Throwable $e) {
            $this->lupakan($slug);
            throw $e;
        }
    }

    public function lupakan(string $slug): void
    {
        if (isset($this->pool[$slug])) {
            $this->pool[$slug]['klien']->tutup();
            unset($this->pool[$slug]);
        }
    }

    public function lupakanSemua(): void
    {
        foreach (array_keys($this->pool) as $slug) {
            $this->lupakan($slug);
        }
    }

    public function kunciTulis(string $slug, callable $kerja, int $tunggu = 0, ?int $ttl = null): mixed
    {
        $lock = Cache::lock("router.tulis.{$slug}", $ttl ?? (int) config('services.mikrotik.kunci_tulis_ttl', 660));

        try {
            $dapat = $tunggu > 0 ? $lock->block($tunggu) : $lock->get();
        } catch (LockTimeoutException) {
            $dapat = false;
        }

        if (! $dapat) {
            throw RouterSibuk::untuk($slug);
        }

        try {
            return $kerja();
        } finally {
            $lock->release();
        }
    }
}
