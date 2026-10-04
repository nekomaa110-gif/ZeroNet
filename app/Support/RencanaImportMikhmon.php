<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

class RencanaImportMikhmon
{
    public const TANPA_BATCH = 'tanpa-batch';

    public static function susun(
        array $users,
        array $records,
        array $profil,
        array $sudahAda,
        string $slug,
        DateTimeZone $zonaRouter,
        DateTimeZone $zonaApp,
    ): array {
        $pertama    = self::loginPertama($records, $zonaRouter);
        $jumlahNama = array_count_values(array_map(fn ($u) => mb_strtolower((string) ($u['name'] ?? '')), $users));

        $ringkasan = [
            'user_router'          => count($users),
            'belum_dipakai'        => 0,
            'terpakai'             => 0,
            'terpakai_tanpa_batch' => 0,
            'sudah_ada'            => 0,
            'bukan_voucher'        => 0,
            'nama_kembar'          => 0,
        ];
        $batch   = [];
        $voucher = [];

        foreach ($users as $u) {
            $nama    = (string) ($u['name'] ?? '');
            $kunci   = mb_strtolower($nama);
            $comment = trim((string) ($u['comment'] ?? ''));

            if ($nama === '' || mb_strlen($nama) > 64) {
                $ringkasan['bukan_voucher']++;
                continue;
            }

            if (($jumlahNama[$kunci] ?? 0) > 1) {
                $ringkasan['nama_kembar']++;
                continue;
            }

            $dipakai = null;

            if (MikhmonRecord::kodeBatch($comment)) {
                $kategori = 'belum_dipakai';
                $asal     = $comment;
            } elseif (MikhmonRecord::waktu($comment) !== null) {
                $kategori = 'terpakai';
                $rec      = $pertama[$kunci] ?? null;
                $asal     = $rec && MikhmonRecord::kodeBatch($rec['batch']) ? $rec['batch'] : self::TANPA_BATCH;
                $dipakai  = $rec['waktu'] ?? null;
            } else {
                $ringkasan['bukan_voucher']++;
                continue;
            }

            if (isset($sudahAda[$kunci])) {
                $ringkasan['sudah_ada']++;
                continue;
            }

            $ringkasan[$kategori]++;
            if ($asal === self::TANPA_BATCH) {
                $ringkasan['terpakai_tanpa_batch']++;
            }

            $profile = (string) ($u['profile'] ?? '');
            $kode    = self::kodeBatchPanel($asal, $slug);
            $meta    = $profil[$profile] ?? null;

            $batch[$kode] ??= ['kode' => $kode, 'comment' => $asal, 'jumlah' => 0, 'profil' => [], 'panjang' => []];
            $batch[$kode]['jumlah']++;
            $batch[$kode]['profil'][$profile]              = ($batch[$kode]['profil'][$profile] ?? 0) + 1;
            $batch[$kode]['panjang'][mb_strlen($nama)] = ($batch[$kode]['panjang'][mb_strlen($nama)] ?? 0) + 1;

            $server = (string) ($u['server'] ?? '');
            $bytes  = (int) ($u['limit-bytes-total'] ?? 0);

            $voucher[] = [
                'batch'        => $kode,
                'kategori'     => $kategori,
                'username'     => $nama,
                'password'     => mb_substr((string) ($u['password'] ?? ''), 0, 64),
                'profile'      => mb_substr($profile, 0, 64),
                'server'       => $server === '' || $server === 'all' ? null : mb_substr($server, 0, 64),
                'comment'      => mb_substr($asal, 0, 64),
                'limit_uptime' => ($u['limit-uptime'] ?? '') !== '' ? mb_substr((string) $u['limit-uptime'], 0, 20) : null,
                'limit_bytes'  => $bytes > 0 ? $bytes : null,
                'price'        => $kategori === 'belum_dipakai' && ($meta['price'] ?? 0) > 0 ? (int) $meta['price'] : null,
                'activated_at' => $dipakai?->setTimezone($zonaApp)->format('Y-m-d H:i:s'),
            ];
        }

        return [
            'ringkasan' => $ringkasan,
            'batch'     => array_values(array_map(fn ($b) => self::lengkapiBatch($b, $profil), $batch)),
            'voucher'   => $voucher,
        ];
    }

    public static function kodeBatchPanel(string $comment, string $slug): string
    {
        $kode = "{$comment}@{$slug}";

        if (mb_strlen($kode) <= 64) {
            return $kode;
        }

        $ekor = '~' . substr(sha1($comment), 0, 8) . "@{$slug}";

        return mb_substr($comment, 0, 64 - mb_strlen($ekor)) . $ekor;
    }

    private static function loginPertama(array $records, DateTimeZone $zona): array
    {
        $hasil = [];

        foreach ($records as $nama) {
            $r = MikhmonRecord::urai((string) $nama, $zona);
            if (! $r || $r['user'] === '') {
                continue;
            }

            $kunci = mb_strtolower($r['user']);
            $lama  = $hasil[$kunci] ?? null;

            if (! $lama || self::lebihAwal($r['waktu'], $lama['waktu'])) {
                $hasil[$kunci] = ['batch' => trim($r['batch']), 'waktu' => $r['waktu']];
            }
        }

        return $hasil;
    }

    private static function lebihAwal(?DateTimeImmutable $a, ?DateTimeImmutable $b): bool
    {
        return $a !== null && ($b === null || $a < $b);
    }

    private static function lengkapiBatch(array $b, array $profil): array
    {
        arsort($b['profil']);
        arsort($b['panjang']);

        $profile = (string) array_key_first($b['profil']);
        $meta    = $profil[$profile] ?? null;
        $mode    = strtolower(substr($b['comment'], 0, 2));

        return [
            'kode'        => $b['kode'],
            'comment'     => $b['comment'],
            'jumlah'      => $b['jumlah'],
            'profile'     => $profile,
            'mode'        => in_array($mode, ['vc', 'up'], true) ? $mode : 'up',
            'code_length' => min(255, (int) array_key_first($b['panjang'])),
            'price'       => ($meta['price'] ?? 0) > 0 ? (int) $meta['price'] : null,
            'validity'    => ($meta['validity'] ?? '') !== '' ? mb_substr((string) $meta['validity'], 0, 20) : null,
        ];
    }
}
