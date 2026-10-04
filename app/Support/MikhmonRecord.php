<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

class MikhmonRecord
{
    public const PISAH = '-|-';

    private const FORMAT_WAKTU = ['Y-m-d H:i:s', 'M/d/Y H:i:s', 'Y-m-d', 'M/d/Y'];

    public static function urai(string $nama, ?DateTimeZone $zona = null): ?array
    {
        if (! str_contains($nama, self::PISAH)) {
            return null;
        }

        $p = explode(self::PISAH, $nama);
        if (count($p) < 3) {
            return null;
        }

        $p = array_pad($p, 9, '');

        return [
            'tanggal' => $p[0],
            'jam'     => $p[1],
            'user'    => $p[2],
            'harga'   => $p[3],
            'ip'      => $p[4],
            'mac'     => $p[5],
            'masa'    => $p[6],
            'profile' => $p[7],
            'batch'   => implode(self::PISAH, array_slice($p, 8)),
            'waktu'   => self::waktu($p[0] . ' ' . $p[1], $zona),
        ];
    }

    public static function waktu(string $teks, ?DateTimeZone $zona = null): ?DateTimeImmutable
    {
        $teks = trim($teks);
        if ($teks === '') {
            return null;
        }

        foreach (self::FORMAT_WAKTU as $format) {
            $d = DateTimeImmutable::createFromFormat($format, $teks, $zona ?? new DateTimeZone('UTC'));

            if ($d && strcasecmp($d->format($format), $teks) === 0) {
                return str_contains($format, 'H') ? $d : $d->setTime(0, 0);
            }
        }

        return null;
    }

    public static function kodeBatch(string $comment): bool
    {
        return (bool) preg_match('/^(vc|up)-/i', $comment);
    }

    public static function angka(string $teks): ?int
    {
        $teks = trim($teks);

        return $teks !== '' && ctype_digit($teks) ? (int) $teks : null;
    }
}
