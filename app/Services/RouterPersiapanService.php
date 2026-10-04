<?php

namespace App\Services;

use App\Models\Router;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class RouterPersiapanService
{
    private const TUNGGU_KUNCI = 5;

    private const TTL_KUNCI = 120;

    public function __construct(
        private RouterGateway $gateway,
        private VoucherRouterService $routers,
        private RouterBackupService $backup,
    ) {}

    public static function namaAkun(): string
    {
        return (string) config('services.mikrotik.akun_panel.nama', 'zeronet-api');
    }

    public static function namaGrup(): string
    {
        return (string) config('services.mikrotik.akun_panel.grup', 'zeronet-api');
    }

    public function periksa(Router $router): array
    {
        try {
            $info   = $this->routers->info($router);
            $status = $this->routers->scriptStatus($router);
            $server = $this->routers->servers($router);
            $c      = $this->routers->client($router);
            $grup   = $this->grupRouter($c);
            $akun   = $this->akunRouter($c);
        } finally {
            $this->routers->disconnect($router);
        }

        $versi = VoucherScriptBuilder::plainVersion($info['version']);

        if ($router->ros_version !== $versi) {
            $router->forceFill(['ros_version' => $versi])->save();
        }

        $pemakai = $akun[$router->username] ?? null;
        $khusus  = $akun[self::namaAkun()] ?? null;

        $jualan = collect($status['profiles'])
            ->filter(fn ($p) => $p['validity'] !== '')
            ->map(fn ($p) => [
                'nama'     => $p['name'],
                'jenis'    => $p['managed'] ? 'panel' : ($p['expmode'] !== '' ? 'lama' : 'kosong'),
                'mode'     => str_starts_with($p['expmode'], 'rem') ? 'rem' : ($p['expmode'] !== '' ? 'ntf' : 'off'),
                'record'   => $p['record'],
                'lock'     => $p['lock'],
                'validity' => $p['validity'],
                'price'    => $p['price'],
                'sprice'   => $p['sprice'],
                'aman'     => VoucherRouterService::namaProfilAman($p['name']),
            ])->values();

        $lama = collect($status['legacy_schedulers'])->filter(fn ($l) => ! $l['disabled'])->pluck('name')->values()->all();

        $akunSelesai = $khusus !== null
            && $router->username === self::namaAkun()
            && $khusus['group'] === self::namaGrup()
            && ! $khusus['disabled'];

        $scriptSelesai = $jualan->isNotEmpty()
            && $status['script_installed'] && $status['script_current']
            && $status['scheduler'] && ! $status['scheduler']['disabled']
            && ! $lama
            && $jualan->every(fn ($p) => $p['jenis'] === 'panel');

        return [
            'koneksi' => [
                'identitas'   => $info['identity'],
                'board'       => $info['board'],
                'versi'       => $versi,
                'tanggal_iso' => $info['iso_date'],
                'server'      => $server,
            ],
            'akun' => [
                'dipakai'     => $router->username,
                'grup'        => $pemakai['group'] ?? null,
                'bisa_kelola' => $pemakai !== null && $this->bisaKelola($grup['grup'][$pemakai['group']]['policy'] ?? []),
                'nama'        => self::namaAkun(),
                'ada'         => $khusus !== null,
                'policy'      => $this->policyTarget($grup)['pakai'],
            ],
            'script' => [
                'terpasang'   => $status['script_installed'],
                'terbaru'     => $status['script_current'],
                'pengawas'    => $status['scheduler'],
                'lama'        => $lama,
                'profil'      => $jualan->all(),
                'profil_lain' => count($status['profiles']) - $jualan->count(),
            ],
            'selesai' => [
                'koneksi' => true,
                'akun'    => $akunSelesai,
                'script'  => $scriptSelesai,
            ],
        ];
    }

    public function pasangAkun(Router $router): array
    {
        return $this->gateway->kunciTulis($router->slug, fn () => $this->pasangAkunTerkunci($router), self::TUNGGU_KUNCI, self::TTL_KUNCI);
    }

    private function pasangAkunTerkunci(Router $router): array
    {
        $nama     = self::namaAkun();
        $namaGrup = self::namaGrup();
        $cfg      = $router->toConfig();

        try {
            $c    = $this->routers->client($router);
            $grup = $this->grupRouter($c);
            $akun = $this->akunRouter($c);

            $pemakai = $akun[$cfg['user']] ?? null;

            if (! $pemakai || ! $this->bisaKelola($grup['grup'][$pemakai['group']]['policy'] ?? [])) {
                throw new Exception(
                    "Akun {$cfg['user']} tidak punya hak mengelola user (policy dan write) di router ini. "
                    . 'Ganti kredensial router ke akun grup full lewat Edit Router, lalu ulangi.'
                );
            }

            $target   = $this->policyTarget($grup);
            $aksiGrup = $this->pastikanGrup($c, $namaGrup, $target['pakai'], $grup);

            $sandi = Str::password(24, symbols: false);
            $ada   = $akun[$nama] ?? null;

            if ($ada) {
                $c->query('/user/set', ['=.id' => $ada['id'], '=group' => $namaGrup, '=password' => $sandi, '=disabled' => 'no']);
            } else {
                $c->query('/user/add', ['=name' => $nama, '=group' => $namaGrup, '=password' => $sandi, '=comment' => 'Akun API panel ZeroNet']);
            }

            try {
                $identitas = $this->uji($cfg, $nama, $sandi, $namaGrup);
            } catch (Throwable $e) {
                throw new Exception("Akun {$nama} gagal dipakai login: {$e->getMessage()}. " . $this->pulihkan($c, $nama, $ada, $cfg));
            }
        } finally {
            $this->routers->disconnect($router);
        }

        $router->update(['username' => $nama, 'password' => $sandi]);
        MikrotikService::forgetRouterCache();
        Cache::forget("router.kesehatan.{$router->slug}");

        return [
            'akun'        => $ada ? ($cfg['user'] === $nama ? 'password diganti' : 'diperbarui') : 'dibuat',
            'grup'        => $aksiGrup,
            'policy'      => $target['pakai'],
            'tak_dikenal' => $target['tak_dikenal'],
            'sebelumnya'  => $cfg['user'],
            'identitas'   => $identitas,
        ];
    }

    private function pastikanGrup(MikrotikClient $c, string $namaGrup, array $policy, array $grup): string
    {
        $sekarang = $grup['grup'][$namaGrup] ?? null;

        if ($sekarang === null) {
            $c->query('/user/group/add', ['=name' => $namaGrup, '=policy' => implode(',', $policy)]);

            return 'dibuat';
        }

        $a = $sekarang['policy'];
        $b = $policy;
        sort($a);
        sort($b);

        if ($a === $b) {
            return 'tidak berubah';
        }

        $c->query('/user/group/set', ['=.id' => $sekarang['id'], '=policy' => implode(',', $policy)]);

        return 'diperbarui';
    }

    private function uji(array $cfg, string $nama, string $sandi, string $namaGrup): string
    {
        $uji = new MikrotikClient($cfg['host'], $nama, $sandi, (int) $cfg['port'], min(max(1, (int) ($cfg['timeout'] ?? 5)), 8));

        try {
            $identitas = (string) ($uji->query('/system/identity/print')[0]['name'] ?? '-');
            $grup      = (string) ($uji->query('/user/print', ['?name' => $nama, '=.proplist' => 'name,group'])[0]['group'] ?? '');
        } finally {
            $uji->tutup();
        }

        if ($grup !== $namaGrup) {
            throw new Exception("akun terbaca di grup \"{$grup}\", bukan {$namaGrup}");
        }

        return $identitas;
    }

    private function pulihkan(MikrotikClient $c, string $nama, ?array $ada, array $cfg): string
    {
        try {
            if (! $ada) {
                foreach ($c->query('/user/print', ['?name' => $nama, '=.proplist' => '.id']) as $u) {
                    $c->query('/user/remove', ['=.id' => $u['.id']]);
                }

                return "Akun {$nama} dicabut lagi; panel tetap memakai akun {$cfg['user']}.";
            }

            if ($cfg['user'] === $nama) {
                $c->query('/user/set', ['=.id' => $ada['id'], '=password' => $cfg['pass']]);

                return 'Password lama dikembalikan; panel tetap memakai akun ini.';
            }
        } catch (Throwable $e) {
            return "Pemulihan juga gagal ({$e->getMessage()}); periksa akun {$nama} di router.";
        }

        return "Panel tetap memakai akun {$cfg['user']}.";
    }

    private function grupRouter(MikrotikClient $c): array
    {
        $grup    = [];
        $dikenal = [];

        foreach ($c->query('/user/group/print', ['=.proplist' => '.id,name,policy']) as $g) {
            $semua = array_filter(explode(',', (string) ($g['policy'] ?? '')));

            $grup[$g['name'] ?? ''] = [
                'id'     => $g['.id'] ?? '',
                'policy' => array_values(array_filter($semua, fn ($p) => ! str_starts_with($p, '!'))),
            ];

            foreach ($semua as $p) {
                $dikenal[ltrim($p, '!')] = true;
            }
        }

        return ['grup' => $grup, 'dikenal' => array_keys($dikenal)];
    }

    private function akunRouter(MikrotikClient $c): array
    {
        $hasil = [];

        foreach ($c->query('/user/print', ['=.proplist' => '.id,name,group,disabled']) as $u) {
            $hasil[$u['name'] ?? ''] = [
                'id'       => $u['.id'] ?? '',
                'group'    => (string) ($u['group'] ?? ''),
                'disabled' => ($u['disabled'] ?? 'false') === 'true',
            ];
        }

        return $hasil;
    }

    private function bisaKelola(array $policy): bool
    {
        return in_array('policy', $policy, true) && in_array('write', $policy, true);
    }

    private function policyTarget(array $grup): array
    {
        $mau     = (array) config('services.mikrotik.akun_panel.policy', []);
        $dikenal = $grup['dikenal'];

        if (! $this->backup->sftpAktif() && ! in_array('ftp', $mau, true)) {
            $mau[] = 'ftp';
        }

        if (! $dikenal) {
            return ['pakai' => $mau, 'tak_dikenal' => []];
        }

        return [
            'pakai'       => array_values(array_intersect($mau, $dikenal)),
            'tak_dikenal' => array_values(array_diff($mau, $dikenal)),
        ];
    }
}
