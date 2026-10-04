<?php

namespace App\Services;

use App\Models\L2tpAccount;
use Exception;
use Symfony\Component\Process\Process;

class L2tpAccountService
{
    public static function pool(): array
    {
        $start    = (int) config('l2tp.pool_start');
        $end      = (int) config('l2tp.pool_end');
        $reserved = (array) config('l2tp.reserved', []);
        $prefix   = self::ipPrefix();

        $used = L2tpAccount::pluck('remote_ip')
            ->map(fn ($ip) => (int) substr(strrchr($ip, '.'), 1))
            ->all();

        $free = [];
        for ($i = $start; $i <= $end; $i++) {
            if (isset($reserved[$i]) || in_array($i, $used, true)) {
                continue;
            }
            $free[] = $prefix.$i;
        }

        return [
            'start'    => $prefix.$start,
            'end'      => $prefix.$end,
            'reserved' => collect($reserved)->mapWithKeys(fn ($note, $octet) => [$prefix.$octet => $note])->all(),
            'free'     => $free,
        ];
    }

    public static function suggestIp(): ?string
    {
        return self::pool()['free'][0] ?? null;
    }

    public static function assertIpAllowed(string $ip, ?int $ignoreId = null): void
    {
        $prefix = self::ipPrefix();
        if (! str_starts_with($ip, $prefix)) {
            throw new Exception("IP harus di dalam range {$prefix}".config('l2tp.pool_start').'–'.config('l2tp.pool_end').'.');
        }

        $octet = (int) substr($ip, strlen($prefix));
        $start = (int) config('l2tp.pool_start');
        $end   = (int) config('l2tp.pool_end');
        if ($octet < $start || $octet > $end) {
            throw new Exception("IP harus di dalam range {$prefix}{$start}–{$prefix}{$end}.");
        }

        $reserved = (array) config('l2tp.reserved', []);
        if (isset($reserved[$octet])) {
            throw new Exception("IP {$ip} dipakai layanan lain ({$reserved[$octet]}), pilih IP lain.");
        }

        $clash = L2tpAccount::where('remote_ip', $ip)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($clash) {
            throw new Exception("IP {$ip} sudah dipakai akun L2TP lain.");
        }
    }

    public static function create(array $data): L2tpAccount
    {
        $account = L2tpAccount::create($data);
        self::sync();

        return $account;
    }

    public static function update(L2tpAccount $account, array $data): L2tpAccount
    {
        $account->update($data);
        self::sync();

        return $account;
    }

    public static function delete(L2tpAccount $account): void
    {
        $account->delete();
        self::sync();
    }

    public static function toggle(L2tpAccount $account): L2tpAccount
    {
        $account->update(['is_active' => ! $account->is_active]);
        self::sync();

        return $account;
    }

    public static function sync(): void
    {
        $serverName = config('l2tp.server_name', 'l2tpd');

        $lines = L2tpAccount::where('is_active', true)
            ->orderBy('username')
            ->get()
            ->map(fn (L2tpAccount $a) => "{$a->username} {$serverName} {$a->secret} {$a->remote_ip}")
            ->implode("\n");

        $content = $lines === '' ? '' : $lines."\n";

        $process = new Process(['sudo', '-n', config('l2tp.sync_script')]);
        $process->setInput($content);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new Exception('Gagal menyinkronkan ke server L2TP: '.trim($process->getErrorOutput() ?: $process->getOutput() ?: 'perintah sync gagal.'));
        }
    }

    private static function ipPrefix(): string
    {
        $local = (string) config('l2tp.local_ip');

        return substr($local, 0, strrpos($local, '.') + 1);
    }
}
