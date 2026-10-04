<?php

namespace App\Services;

use App\Models\RadAcct;
use App\Models\RadCheck;
use App\Models\RadUserGroup;
use App\Models\Router;
use App\Support\MikhmonRecord;

class VoucherLookupService
{
    private const API_TIMEOUT = 10;

    private const MIRIP_MAKS = 8;

    private const RIWAYAT_MAKS = 20;

    public function __construct(private RouterGateway $gateway) {}

    public function cek(string $kode, string $pass = '', array $slugs = [], bool $ambilLog = false): array
    {
        $kode = trim($kode);
        $pass = trim($pass);

        $hasil = [
            'kode'   => $kode,
            'pass'   => $pass,
            'mode'   => $kode !== '' ? 'kode' : 'password',
            'gagal'  => [],
            'hasil'  => [],
            'cocok'  => [],
            'mirip'  => [],
            'radius' => null,
            'riwayat' => null,
            'ketemu' => false,
        ];

        $routers = Router::query()
            ->when($slugs, fn ($q) => $q->whereIn('slug', $slugs))
            ->orderBy('id')
            ->get();

        $simpanan     = [];
        $recordGlobal = [];

        foreach ($routers as $router) {
            $data                    = $this->tarikData($router, $ambilLog);
            $simpanan[$router->slug] = $data;

            if (isset($data['error'])) {
                $hasil['gagal'][] = [
                    'nama'  => $router->name,
                    'host'  => $router->host,
                    'pesan' => $data['error'],
                ];
                continue;
            }

            if ($kode === '') {
                foreach ($data['users'] as $u) {
                    if (($u['password'] ?? '') === $pass) {
                        $hasil['cocok'][] = [
                            'router'   => $router->name,
                            'slug'     => $router->slug,
                            'nama'     => $u['name'] ?? '',
                            'profile'  => $u['profile'] ?? '',
                            'limit'    => $u['limit-uptime'] ?? '-',
                            'uptime'   => $u['uptime'] ?? '0s',
                            'comment'  => trim($u['comment'] ?? ''),
                        ];
                    }
                }
                continue;
            }

            $user    = $this->cariUser($data['users'], $kode);
            $records = $this->cariRecord($data['scripts'], $kode);

            foreach ($records as $r) {
                $recordGlobal[] = ['router' => $router, 'record' => $r];
            }

            if (! $user && ! $records) {
                continue;
            }

            $hasil['ketemu'] = true;
            $hasil['hasil'][] = $this->susunLaporan($router, $data, $kode, $pass, $user, $records);
        }

        if ($kode !== '' && ! $hasil['ketemu']) {
            $hasil['radius'] = $this->cariRadius($kode, $simpanan);

            if (! $hasil['radius']) {
                $hasil['mirip'] = $this->cariMirip($kode, $simpanan);
            }
        }

        if ($recordGlobal) {
            $hasil['riwayat'] = $this->susunRiwayat($recordGlobal, $kode, $simpanan);
        }

        $hasil['ketemu'] = $hasil['ketemu'] || $hasil['radius'] !== null || $hasil['cocok'] !== [];

        return $hasil;
    }

    private function tarikData(Router $router, bool $ambilLog): array
    {
        try {
            $c = $this->gateway->klien($router->slug, $router->toConfig(), self::API_TIMEOUT);

            $clock = $c->query('/system/clock/print')[0] ?? [];

            return [
                'jam'      => $this->waktuRouter($clock),
                'users'    => $c->query('/ip/hotspot/user/print', [
                    '=.proplist' => 'name,password,profile,limit-uptime,uptime,bytes-in,bytes-out,comment,disabled,server,address,mac-address',
                ]),
                'scripts'  => $c->query('/system/script/print', ['=.proplist' => 'name']),
                'active'   => $c->query('/ip/hotspot/active/print'),
                'hosts'    => $c->query('/ip/hotspot/host/print'),
                'cookies'  => $c->query('/ip/hotspot/cookie/print'),
                'profiles' => $c->query('/ip/hotspot/user/profile/print', ['=.proplist' => 'name,rate-limit,shared-users,on-login']),
                'logs'     => $ambilLog ? $c->query('/log/print') : [],
            ];
        } catch (\Throwable $e) {
            $this->gateway->lupakan($router->slug);

            return ['error' => $e->getMessage()];
        }
    }

    private function susunLaporan(Router $router, array $data, string $kode, string $pass, ?array $user, array $records): array
    {
        $now    = $data['jam'];
        $record = $records[0] ?? null;

        $baris = [
            'router'  => ['nama' => $router->name, 'host' => $router->host, 'slug' => $router->slug],
            'jam'     => $now,
            'jam_teks' => $now?->format('d M Y H:i:s') ?? '?',
            'user'    => null,
            'masa'    => null,
            'record'  => $record,
            'records' => $records,
            'sesi'    => [],
            'hosts'   => [],
            'cookies' => [],
            'mac'     => '',
            'log'     => [],
        ];

        if ($user) {
            $baris['user'] = $this->susunUser($data, $user, $pass);
            $baris['masa'] = $this->susunMasa($user, $now, (bool) $records);
            $baris['vonis'] = $this->vonis($baris['user'], $baris['masa'], $now);
        } else {
            $baris['vonis'] = [
                'teks'  => 'PERNAH DIPAKAI, LALU DIHAPUS DARI ROUTER',
                'level' => 'err',
                'catatan' => [
                    'Kode tidak ada lagi di /ip hotspot user, tapi catatan penjualannya ada.',
                    'Ini sisa mode "Remove" lama: voucher yang habis masa aktif ikut terhapus.',
                    'Jangan di-add ulang (kartu ini sudah laku dan sudah dipakai).',
                ],
            ];
        }

        foreach ($data['active'] as $a) {
            if (strcasecmp($a['user'] ?? '', $kode) === 0) {
                $baris['sesi'][] = $a;
                if (($a['mac-address'] ?? '') !== '') {
                    $baris['mac'] = $a['mac-address'];
                }
            }
        }

        if ($baris['mac'] === '' && $record) {
            $baris['mac'] = $record['mac'];
        }

        if ($baris['mac'] !== '') {
            foreach ($data['hosts'] as $h) {
                if (strcasecmp($h['mac-address'] ?? '', $baris['mac']) === 0) {
                    $baris['hosts'][] = $h + [
                        'trafik' => $this->bytes($h['bytes-out'] ?? '0') . ' download / ' . $this->bytes($h['bytes-in'] ?? '0') . ' upload',
                    ];
                }
            }
        }

        foreach ($data['cookies'] as $c) {
            if (strcasecmp($c['user'] ?? '', $kode) === 0) {
                $baris['cookies'][] = $c;
            }
        }

        foreach ($data['logs'] as $l) {
            if (stripos($l['message'] ?? '', $kode) !== false) {
                $baris['log'][] = $l;
            }
        }
        $baris['log'] = array_slice($baris['log'], -25);

        return $baris;
    }

    private function susunUser(array $data, array $user, string $pass): array
    {
        $limit  = $this->keDetik($user['limit-uptime'] ?? '');
        $pakai  = $this->keDetik($user['uptime'] ?? '');
        $mati1s = ($user['limit-uptime'] ?? '') === '1s';

        $jatah  = $mati1s ? $this->jatahProfil($data['users'], $user['profile'] ?? '') : $limit;
        $persen = $jatah > 0 ? min(100, (int) round($pakai / $jatah * 100)) : 0;

        return [
            'nama'       => $user['name'] ?? '',
            'password'   => $user['password'] ?? '',
            'pass_cocok' => $pass !== '' ? ($pass === ($user['password'] ?? '')) : null,
            'server'     => $user['server'] ?? '-',
            'profile'    => $user['profile'] ?? '-',
            'profil_info' => $this->infoProfil($data['profiles'], $user['profile'] ?? ''),
            'limit_teks' => $user['limit-uptime'] ?? '-',
            'pakai_detik' => $pakai,
            'limit_detik' => $jatah,
            'persen'     => $persen,
            'bar_level'  => $mati1s || $persen >= 100 ? 'err' : ($persen >= 80 ? 'warn' : 'ok'),
            'kuota_teks' => $this->kuotaTeks($pakai, $jatah, $mati1s),
            'data_teks'  => $this->bytes($user['bytes-out'] ?? '0') . ' download / ' . $this->bytes($user['bytes-in'] ?? '0') . ' upload',
            'mac_kunci'  => $user['mac-address'] ?? '',
            'comment'    => trim($user['comment'] ?? ''),
            'nonaktif'   => ($user['disabled'] ?? 'false') === 'true',
            'mati1s'     => ($user['limit-uptime'] ?? '') === '1s',
        ];
    }

    private function susunMasa(array $user, ?\DateTimeImmutable $now, bool $adaRecord): array
    {
        $exp = $this->keWaktu(trim($user['comment'] ?? ''));

        if (! $exp) {
            return [
                'exp'         => null,
                'exp_teks'    => 'belum ditentukan (dihitung sejak login pertama)',
                'sisa_detik'  => null,
                'sisa_teks'   => $adaRecord ? '' : 'kartu belum pernah dipakai, aman dijual',
                'lewat'       => false,
            ];
        }

        $sisa = $now ? $exp->getTimestamp() - $now->getTimestamp() : null;

        return [
            'exp'        => $exp,
            'exp_teks'   => $exp->format('d M Y H:i:s'),
            'sisa_detik' => $sisa,
            'sisa_teks'  => $sisa === null
                ? '-'
                : ($sisa > 0 ? $this->durasi($sisa) . ' lagi' : 'sudah lewat ' . $this->durasi(-$sisa)),
            'lewat'      => $sisa !== null && $sisa <= 0,
        ];
    }

    private function vonis(array $user, array $masa, ?\DateTimeImmutable $now): array
    {
        if ($user['nonaktif']) {
            return ['teks' => 'DINONAKTIFKAN MANUAL (disabled=yes)', 'level' => 'err', 'catatan' => []];
        }

        if ($user['mati1s']) {
            return [
                'teks'  => 'DIMATIKAN SCHEDULER (masa aktif sudah lewat)',
                'level' => 'err',
                'catatan' => ['limit-uptime disetel 1s oleh sistem  karena batas sudah tercapai.'],
            ];
        }

        if ($masa['exp'] && $masa['lewat']) {
            return ['teks' => 'HANGUS (masa aktif 1×24 jam sudah lewat)', 'level' => 'err', 'catatan' => []];
        }

        if ($user['limit_detik'] > 0 && $user['pakai_detik'] >= $user['limit_detik']) {
            return ['teks' => 'KUOTA HABIS (jatah jam sudah terpakai penuh)', 'level' => 'err', 'catatan' => []];
        }

        if (! $masa['exp'] && $user['comment'] !== '') {
            return [
                'teks'  => 'BELUM PERNAH LOGIN (kartu masih perawan)',
                'level' => 'ok',
                'catatan' => ['Kalau pelanggan tetap tidak bisa masuk, masalahnya di pengetikan kode atau sinyal, bukan vouchernya.'],
            ];
        }

        if ($masa['exp'] && $now) {
            return ['teks' => 'MASIH AKTIF', 'level' => 'ok', 'catatan' => []];
        }

        return ['teks' => 'ADA DI ROUTER (status tidak biasa, periksa manual)', 'level' => 'warn', 'catatan' => []];
    }

    private function cariRadius(string $kode, array $simpanan): ?array
    {
        $baris = RadCheck::where('username', $kode)->get();
        if ($baris->isEmpty()) {
            return null;
        }

        $expTeks = (string) ($baris->firstWhere('attribute', 'Expiration')->value ?? '');
        $ditolak = $baris->contains(fn ($b) => $b->attribute === 'Auth-Type' && $b->value === 'Reject');
        $exp     = $expTeks !== '' ? \DateTimeImmutable::createFromFormat('d M Y H:i:s', $expTeks) : null;
        $now     = new \DateTimeImmutable();
        $sisa    = $exp ? $exp->getTimestamp() - $now->getTimestamp() : null;

        if ($ditolak) {
            $vonis = ['teks' => 'PELANGGAN BULANAN (DINONAKTIFKAN, Auth-Type := Reject)', 'level' => 'err'];
        } elseif ($sisa !== null && $sisa <= 0) {
            $vonis = ['teks' => 'PELANGGAN BULANAN (MASA AKTIF HABIS)', 'level' => 'err'];
        } else {
            $vonis = ['teks' => 'PELANGGAN BULANAN (AKTIF)', 'level' => 'ok'];
        }

        $hotspot = [];
        foreach ($simpanan as $slug => $data) {
            if (isset($data['error'])) {
                continue;
            }
            foreach ($data['active'] as $a) {
                if (strcasecmp($a['user'] ?? '', $kode) === 0) {
                    $hotspot[] = ['slug' => $slug] + $a;
                }
            }
        }

        return [
            'username'  => $kode,
            'group'     => (string) (RadUserGroup::where('username', $kode)->value('groupname') ?? '-'),
            'exp_teks'  => $expTeks !== '' ? $expTeks : '(tanpa batas)',
            'sisa_teks' => $sisa === null ? '-' : ($sisa > 0 ? $this->durasi($sisa) . ' lagi' : 'sudah lewat ' . $this->durasi(-$sisa)),
            'diblokir'  => $ditolak,
            'vonis'     => $vonis,
            'sesi'      => RadAcct::where('username', $kode)->orderByDesc('radacctid')->first(),
            'hotspot'   => $hotspot,
        ];
    }

    private function susunRiwayat(array $recordGlobal, string $kode, array $simpanan): ?array
    {
        $mac    = '';
        $router = null;

        foreach ($recordGlobal as $g) {
            if (($g['record']['mac'] ?? '') !== '') {
                $mac    = $g['record']['mac'];
                $router = $g['router'];
                break;
            }
        }

        if ($mac === '' || ! $router) {
            return null;
        }

        $data = $simpanan[$router->slug] ?? [];
        if (! $data || isset($data['error'])) {
            return null;
        }

        $items = [];
        foreach ($data['scripts'] as $s) {
            $r = $this->uraiRecord($s['name'] ?? '');
            if ($r && strcasecmp($r['mac'], $mac) === 0) {
                $r['ini'] = strcasecmp($r['user'], $kode) === 0;
                $items[]  = $r;
            }
        }

        usort($items, fn ($a, $b) => ($a['waktu']?->getTimestamp() ?? 0) <=> ($b['waktu']?->getTimestamp() ?? 0));

        return [
            'mac'    => $mac,
            'router' => $router->name,
            'total'  => count($items),
            'items'  => array_slice($items, -self::RIWAYAT_MAKS),
        ];
    }

    private function cariMirip(string $kode, array $simpanan): array
    {
        $mirip = [];

        foreach ($simpanan as $slug => $data) {
            if (isset($data['error'])) {
                continue;
            }

            foreach ($data['users'] as $u) {
                $n = $u['name'] ?? '';
                if ($n !== '' && strcasecmp($n, $kode) !== 0 && levenshtein(strtolower($n), strtolower($kode)) <= 1) {
                    $mirip[] = [
                        'nama'    => $n,
                        'pass'    => $u['password'] ?? '',
                        'slug'    => $slug,
                        'profile' => $u['profile'] ?? '',
                        'uptime'  => $u['uptime'] ?? '0s',
                        'comment' => trim($u['comment'] ?? ''),
                    ];
                }
            }
        }

        return array_slice($mirip, 0, self::MIRIP_MAKS);
    }

    private function cariUser(array $users, string $kode): ?array
    {
        foreach ($users as $u) {
            if (strcasecmp($u['name'] ?? '', $kode) === 0) {
                return $u;
            }
        }

        return null;
    }

    private function cariRecord(array $scripts, string $kode): array
    {
        $hasil = [];

        foreach ($scripts as $s) {
            $r = $this->uraiRecord($s['name'] ?? '');
            if ($r && strcasecmp($r['user'], $kode) === 0) {
                $hasil[] = $r;
            }
        }

        usort($hasil, fn ($a, $b) => ($a['waktu']?->getTimestamp() ?? 0) <=> ($b['waktu']?->getTimestamp() ?? 0));

        return $hasil;
    }

    private function uraiRecord(string $nama): ?array
    {
        $r = MikhmonRecord::urai($nama);

        if (! $r) {
            return null;
        }

        return $r + [
            'harga_teks' => $r['harga'] !== '' ? 'Rp ' . number_format((float) $r['harga'], 0, ',', '.') : '-',
            'waktu_teks' => $r['waktu']?->format('d M Y H:i:s') ?? trim($r['tanggal'] . ' ' . $r['jam']),
            'ini'        => false,
        ];
    }

    private function waktuRouter(array $clock): ?\DateTimeImmutable
    {
        if (! isset($clock['date'], $clock['time'])) {
            return null;
        }

        return $this->keWaktu($clock['date'] . ' ' . $clock['time']);
    }

    public function keWaktu(string $teks): ?\DateTimeImmutable
    {
        return MikhmonRecord::waktu($teks);
    }

    public function keDetik(string $durasi): int
    {
        $total = 0;
        if (preg_match_all('/(\d+)([wdhms])/', $durasi, $m, PREG_SET_ORDER)) {
            foreach ($m as $p) {
                $total += (int) $p[1] * ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1][$p[2]];
            }
        }

        return $total;
    }

    public function durasi(int $detik): string
    {
        if ($detik < 60) {
            return "{$detik} detik";
        }

        $hari  = intdiv($detik, 86400);
        $jam   = intdiv($detik % 86400, 3600);
        $menit = intdiv($detik % 3600, 60);

        $bagian = [];
        if ($hari)  $bagian[] = "{$hari} hari";
        if ($jam)   $bagian[] = "{$jam} jam";
        if ($menit) $bagian[] = "{$menit} menit";

        return implode(' ', $bagian) ?: '0 menit';
    }

    private function kuotaTeks(int $pakai, int $jatah, bool $mati1s): string
    {
        if ($jatah <= 0) {
            return 'tanpa batas jam, terpakai ' . $this->durasi($pakai);
        }

        if ($mati1s) {
            return $this->durasi($jatah) . ($pakai < $jatah ? ' (terpakai ' . $this->durasi($pakai) . ')' : '');
        }

        $sisa = $jatah - $pakai;

        return $this->durasi($pakai) . ' terpakai dari ' . $this->durasi($jatah)
            . ($sisa > 0 ? ' (sisa ' . $this->durasi($sisa) . ')' : ' (habis)');
    }

    private function jatahProfil(array $users, string $profile): int
    {
        $hitung = [];

        foreach ($users as $u) {
            $l = $u['limit-uptime'] ?? '';
            if ($l === '' || $l === '1s' || ($u['profile'] ?? '') !== $profile) {
                continue;
            }
            $hitung[$l] = ($hitung[$l] ?? 0) + 1;
        }

        if (! $hitung) {
            return 0;
        }

        arsort($hitung);

        return $this->keDetik((string) array_key_first($hitung));
    }

    private function infoProfil(array $profiles, string $nama): string
    {
        foreach ($profiles as $p) {
            if (($p['name'] ?? '') !== $nama) {
                continue;
            }

            $harga = $masa = '';
            if (preg_match('/:put\s*\(",([^,]*),([^,]*),([^,]*),/', $p['on-login'] ?? '', $m)) {
                $harga = $m[2];
                $masa  = $m[3];
            }

            $bagian = array_filter([
                ($p['rate-limit'] ?? '') !== '' ? $p['rate-limit'] : null,
                $harga !== '' ? 'Rp ' . number_format((float) $harga, 0, ',', '.') : null,
                $masa !== '' ? "masa {$masa}" : null,
            ]);

            return $bagian ? implode(' · ', $bagian) : '';
        }

        return '';
    }

    public function bytes(string $n): string
    {
        $b = (float) $n;
        foreach (['B', 'KB', 'MB', 'GB'] as $satuan) {
            if ($b < 1024 || $satuan === 'GB') {
                return round($b, $b < 10 ? 1 : 0) . " {$satuan}";
            }
            $b /= 1024;
        }

        return $n;
    }
}
