<?php

namespace App\Services;

use RuntimeException;

class VoucherCodeGenerator
{
    private const MAKS_COBA = 200;

    private const HURUF_SAJA = ['lower', 'upper', 'upplow'];

    public function make(
        int $quantity,
        string $charset,
        int $length,
        string $mode = 'vc',
        int $passwordLength = 4,
        string $prefix = '',
        string $suffix = '',
        array $taken = [],
    ): array {
        $chars = (string) (config("voucher.charsets.{$charset}.chars") ?? '');

        if ($chars === '') {
            throw new RuntimeException("Jenis karakter \"{$charset}\" tidak dikenal.");
        }

        $angka = (string) (config('voucher.charsets.num.chars') ?? '23456789');
        $ekor  = self::ekorAngka($mode, $charset, $length);
        $ruang = $this->ruangKemungkinan(strlen($chars), $length - $ekor) * $this->ruangKemungkinan(strlen($angka), $ekor);
        if ($ruang < $quantity * 4) {
            throw new RuntimeException(
                'Kombinasi kode terlalu sedikit untuk jumlah segini. Tambah panjang karakter, '
                . 'atau kurangi jumlah voucher.'
            );
        }

        $hasil = [];

        for ($i = 0; $i < $quantity; $i++) {
            $coba = 0;

            do {
                $kode = $prefix . $this->acak($chars, $length - $ekor) . $this->acak($angka, $ekor) . $suffix;
                $coba++;

                if ($coba >= self::MAKS_COBA) {
                    throw new RuntimeException(
                        'Terlalu banyak kode yang bentrok dengan kartu lama. Ganti prefix atau '
                        . 'tambah panjang karakter, lalu coba lagi.'
                    );
                }
            } while (isset($taken[mb_strtolower($kode)]));

            $taken[mb_strtolower($kode)] = true;

            $hasil[] = [
                'username' => $kode,

                'password' => $mode === 'up' ? $this->acak($angka, max(3, $passwordLength)) : $kode,
            ];
        }

        return $hasil;
    }

    public static function ekorAngka(string $mode, string $charset, int $length): int
    {
        return $mode === 'vc' && in_array($charset, self::HURUF_SAJA, true) ? intdiv($length, 2) : 0;
    }

    private function acak(string $chars, int $length): string
    {
        $maks = strlen($chars) - 1;
        $out  = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $maks)];
        }

        return $out;
    }

    private function ruangKemungkinan(int $basis, int $length): float
    {
        return min(PHP_INT_MAX, $basis ** min($length, 12));
    }

    public function batchCode(string $mode, string $label = ''): string
    {
        $label = preg_replace('/[^A-Za-z0-9 _.]/', '', $label) ?? '';
        $label = trim(mb_substr($label, 0, 20));

        return ($mode === 'up' ? 'up' : 'vc')
            . '-' . random_int(100, 999)
            . '-' . date('m.d.y')
            . '-' . $label;
    }
}
