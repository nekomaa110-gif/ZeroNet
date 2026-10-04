<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterLog;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RouterLogService
{
    public const SIMPAN_HARI = 7;

    private const BULAN = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public function simpan(Router $router, array $baris, array $jam): array
    {
        $zona     = VoucherRouterService::zonaDariJam($jam);
        $sekarang = $this->jamRouter($jam, $zona);
        $kunci    = "router.log.akhir.{$router->id}";
        $akhir    = Cache::get($kunci);

        $nomor = array_map(fn ($r) => hexdec(ltrim((string) ($r['.id'] ?? '0'), '*')), $baris);
        if (! $nomor) {
            return ['baru' => 0, 'celah' => false, 'reboot' => false];
        }

        $maks   = max($nomor);
        $reboot = $akhir !== null && $maks < $akhir;
        if ($reboot) {
            $akhir = null;
        }
        $celah = $akhir !== null && min($nomor) > $akhir + 1;

        $batas = $sekarang->modify('-' . self::SIMPAN_HARI . ' days');
        $rows  = [];
        foreach ($baris as $i => $r) {
            if ($akhir !== null && $nomor[$i] <= $akhir) {
                continue;
            }
            if (! str_contains((string) ($r['topics'] ?? ''), 'hotspot')) {
                continue;
            }
            $waktu = $this->waktu((string) ($r['time'] ?? ''), $sekarang, $zona);
            if (! $waktu || $waktu < $batas) {
                continue;
            }
            $rows[] = ['router_id' => $router->id, 'topik' => mb_substr((string) $r['topics'], 0, 48)]
                + self::urai((string) ($r['message'] ?? ''))
                + [
                    'waktu'      => $waktu->setTimezone(new DateTimeZone(config('app.timezone')))->format('Y-m-d H:i:s'),
                    'hash'       => sha1($waktu->format('c') . '|' . ($r['.id'] ?? '') . '|' . ($r['message'] ?? '')),
                    'created_at' => now(),
                ];
        }

        $baru = 0;
        foreach (array_chunk($rows, 500) as $potong) {
            $baru += DB::table('router_logs')->insertOrIgnore($potong);
        }

        Cache::forever($kunci, $maks);

        return ['baru' => $baru, 'celah' => $celah, 'reboot' => $reboot];
    }

    public static function urai(string $pesan): array
    {
        $pengguna = null;
        $ip       = null;
        $isi      = trim($pesan);

        if (preg_match('/^(?:->:\s*)?(.*?)\s*\(([0-9A-Fa-f.:]+)\):\s*(.+)$/', $isi, $m)) {
            $pengguna = $m[1] !== '' ? mb_substr($m[1], 0, 64) : null;
            $ip       = $m[2];
            $isi      = $m[3];
        }

        $kejadian = match (true) {
            str_starts_with($isi, 'logged in')        => 'masuk',
            str_starts_with($isi, 'logged out')       => 'keluar',
            str_starts_with($isi, 'login failed')     => 'gagal',
            str_starts_with($isi, 'trying to log in') => 'mencoba',
            default                                   => 'lain',
        };

        return ['pengguna' => $pengguna, 'ip' => $ip, 'kejadian' => $kejadian, 'pesan' => mb_substr($isi, 0, 255)];
    }

    public function waktu(string $teks, DateTimeImmutable $sekarang, DateTimeZone $zona): ?DateTimeImmutable
    {
        $teks = strtolower(trim($teks));

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $teks)) {
            $w = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sekarang->format('Y-m-d') . " {$teks}", $zona);

            return $w > $sekarang->modify('+5 minutes') ? $w->modify('-1 day') : $w;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}:\d{2})$/', $teks, $m)) {
            return DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}", $zona) ?: null;
        }

        if (preg_match('/^(\d{2})-(\d{2}) (\d{2}:\d{2}:\d{2})$/', $teks, $m)) {
            return $this->tanpaTahun((int) $m[1], (int) $m[2], $m[3], $sekarang, $zona);
        }

        if (preg_match('/^([a-z]{3})\/(\d{2})\/(\d{4}) (\d{2}:\d{2}:\d{2})$/', $teks, $m) && isset(self::BULAN[$m[1]])) {
            return DateTimeImmutable::createFromFormat('Y-n-d H:i:s', "{$m[3]}-" . self::BULAN[$m[1]] . "-{$m[2]} {$m[4]}", $zona) ?: null;
        }

        if (preg_match('/^([a-z]{3})\/(\d{2}) (\d{2}:\d{2}:\d{2})$/', $teks, $m) && isset(self::BULAN[$m[1]])) {
            return $this->tanpaTahun(self::BULAN[$m[1]], (int) $m[2], $m[3], $sekarang, $zona);
        }

        return null;
    }

    public function bersihkan(): int
    {
        $batas  = now()->subDays(self::SIMPAN_HARI);
        $hapus  = 0;

        do {
            $n = RouterLog::where('waktu', '<', $batas)->orderBy('waktu')->limit(5000)->delete();
            $hapus += $n;
        } while ($n > 0);

        return $hapus;
    }

    private function tanpaTahun(int $bulan, int $hari, string $jam, DateTimeImmutable $sekarang, DateTimeZone $zona): ?DateTimeImmutable
    {
        $w = DateTimeImmutable::createFromFormat('Y-n-j H:i:s', $sekarang->format('Y') . "-{$bulan}-{$hari} {$jam}", $zona);

        return $w && $w > $sekarang->modify('+1 day') ? $w->modify('-1 year') : ($w ?: null);
    }

    private function jamRouter(array $jam, DateTimeZone $zona): DateTimeImmutable
    {
        $tanggal = strtolower(trim((string) ($jam['date'] ?? '')));
        $waktu   = trim((string) ($jam['time'] ?? ''));

        if (preg_match('/^([a-z]{3})\/(\d{2})\/(\d{4})$/', $tanggal, $m) && isset(self::BULAN[$m[1]])) {
            $tanggal = sprintf('%s-%02d-%s', $m[3], self::BULAN[$m[1]], $m[2]);
        }

        return DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "{$tanggal} {$waktu}", $zona)
            ?: new DateTimeImmutable('now', $zona);
    }
}
