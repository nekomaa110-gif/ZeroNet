<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class PerangkatDikenal
{
    public const ADMIN = 'zn_perangkat';

    public const PELANGGAN = 'zn_perangkat_pelanggan';

    private const MAKS_AKUN = 5;

    private const UMUR_MENIT = 60 * 24 * 90;

    public static function dikenal(Request $request, string $cookie, string $username): bool
    {
        return in_array(self::sidik($username), self::daftar($request, $cookie), true);
    }

    public static function tandai(Request $request, string $cookie, string $username): void
    {
        $sidik  = self::sidik($username);
        $daftar = array_values(array_diff(self::daftar($request, $cookie), [$sidik]));

        array_unshift($daftar, $sidik);

        Cookie::queue(Cookie::make(
            $cookie,
            json_encode(array_slice($daftar, 0, self::MAKS_AKUN)),
            self::UMUR_MENIT,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    private static function daftar(Request $request, string $cookie): array
    {
        $isi = json_decode((string) $request->cookie($cookie, ''), true);

        return is_array($isi) ? array_values(array_filter($isi, 'is_string')) : [];
    }

    private static function sidik(string $username): string
    {
        return hash_hmac('sha256', Str::lower(trim($username)), (string) config('app.key'));
    }
}
