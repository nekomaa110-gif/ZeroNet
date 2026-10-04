<?php

namespace App\Services;

use App\Models\Router;
use Exception;

class VoucherRouterService
{
    private const TUNGGU_KUNCI = 5;

    private const TTL_KUNCI = 120;

    public function __construct(
        private VoucherScriptBuilder $builder,
        private RouterGateway $gateway,
    ) {}

    public function client(Router $router): MikrotikClient
    {
        return $this->gateway->klien($router->slug, $router->toConfig());
    }

    public function disconnect(?Router $router = null): void
    {
        $router ? $this->gateway->lupakan($router->slug) : $this->gateway->lupakanSemua();
    }

    public function info(Router $router): array
    {
        $c   = $this->client($router);
        $res = $c->query('/system/resource/print')[0] ?? [];
        $idn = $c->query('/system/identity/print')[0] ?? [];

        $version = (string) ($res['version'] ?? '');

        return [
            'version'  => $version,
            'board'    => (string) ($res['board-name'] ?? '-'),
            'identity' => (string) ($idn['name'] ?? '-'),
            'iso_date' => VoucherScriptBuilder::usesIsoDate($version),
        ];
    }

    public function clockZone(Router $router): \DateTimeZone
    {
        return self::zonaDariJam($this->client($router)->query('/system/clock/print')[0] ?? []);
    }

    public static function zonaDariJam(array $clock): \DateTimeZone
    {
        $offset = trim((string) ($clock['gmt-offset'] ?? ''));

        if (preg_match('/^([+-]?)(\d{1,2}):(\d{2})$/', $offset, $m) || preg_match('/^([+-])(\d{2})(\d{2})$/', $offset, $m)) {
            return new \DateTimeZone(($m[1] === '-' ? '-' : '+') . str_pad($m[2], 2, '0', STR_PAD_LEFT) . ':' . $m[3]);
        }

        if (is_numeric($offset)) {
            $detik = (int) $offset;
            $tanda = $detik < 0 ? '-' : '+';
            $detik = abs($detik);

            return new \DateTimeZone(sprintf('%s%02d:%02d', $tanda, intdiv($detik, 3600), intdiv($detik % 3600, 60)));
        }

        return new \DateTimeZone(config('app.timezone', 'UTC'));
    }

    public function servers(Router $router): array
    {
        return collect($this->client($router)->query('/ip/hotspot/print', ['=.proplist' => 'name']))
            ->pluck('name')->filter()->values()->all();
    }

    public function profiles(Router $router): array
    {
        $rows = $this->client($router)->query('/ip/hotspot/user/profile/print', [
            '=.proplist' => '.id,name,rate-limit,shared-users,on-login,address-pool,parent-queue,idle-timeout',
        ]);

        $script = (string) config('voucher.script_name');

        return collect($rows)->map(function (array $p) use ($script) {
            $onLogin = (string) ($p['on-login'] ?? '');
            $meta    = self::parseOnLogin($onLogin);

            return [
                'id'           => $p['.id'] ?? '',
                'name'         => $p['name'] ?? '',
                'rate_limit'   => $p['rate-limit'] ?? '',
                'shared_users' => $p['shared-users'] ?? '',
                'address_pool' => $p['address-pool'] ?? '',
                'parent_queue' => $p['parent-queue'] ?? '',
                'idle_timeout' => $p['idle-timeout'] ?? '',
                'on_login'     => $onLogin,

                'managed'      => str_contains($onLogin, $script),
                'has_script'   => $onLogin !== '',
                ...$meta,
            ];
        })->values()->all();
    }

    public static function parseOnLogin(string $onLogin): array
    {
        $parts = explode(',', $onLogin);

        $expmode = trim($parts[1] ?? '');

        return [
            'expmode'  => $expmode,
            'record'   => str_ends_with($expmode, 'c'),
            'price'    => (int) trim($parts[2] ?? '0'),
            'validity' => trim($parts[3] ?? ''),
            'sprice'   => (int) trim($parts[4] ?? '0'),
            'lock'     => trim($parts[6] ?? '') === 'Enable',
        ];
    }

    public function existingUsernames(Router $router): array
    {
        $rows = $this->client($router)->query('/ip/hotspot/user/print', ['=.proplist' => 'name']);

        $names = [];
        foreach ($rows as $r) {
            if (($r['name'] ?? '') !== '') {
                $names[mb_strtolower($r['name'])] = true;
            }
        }

        return $names;
    }

    public function userIdentities(Router $router): array
    {
        $rows = $this->client($router)->query('/ip/hotspot/user/print', ['=.proplist' => 'name,comment,password']);

        $out = [];
        foreach ($rows as $r) {
            if (($r['name'] ?? '') !== '') {
                $out[mb_strtolower($r['name'])] = [
                    'comment'  => (string) ($r['comment'] ?? ''),
                    'password' => array_key_exists('password', $r) ? (string) $r['password'] : null,
                ];
            }
        }

        return $out;
    }

    public function commonLimits(Router $router): array
    {
        $rows = $this->client($router)->query('/ip/hotspot/user/print', [
            '=.proplist' => 'profile,limit-uptime',
        ]);

        $hitung = [];
        foreach ($rows as $r) {
            $limit   = $r['limit-uptime'] ?? '';
            $profile = $r['profile'] ?? '';

            if ($limit === '' || $limit === '1s' || $limit === '00:00:00' || $profile === '') {
                continue;
            }

            $hitung[$profile][$limit] = ($hitung[$profile][$limit] ?? 0) + 1;
        }

        $hasil = [];
        foreach ($hitung as $profile => $daftar) {
            arsort($daftar);
            $hasil[$profile] = (string) array_key_first($daftar);
        }

        return $hasil;
    }

    public function userStates(Router $router): array
    {
        $rows = $this->client($router)->query('/ip/hotspot/user/print', [
            '=.proplist' => 'name,profile,comment,uptime,limit-uptime,bytes-in,bytes-out,disabled',
        ]);

        $out = [];
        foreach ($rows as $r) {
            if (($r['name'] ?? '') !== '') {
                $out[mb_strtolower($r['name'])] = $r;
            }
        }

        return $out;
    }

    public function addUsers(Router $router, array $rows, ?callable $progress = null): array
    {
        $chunkSize = (int) config('voucher.sync_chunk', 25);
        $client    = $this->client($router);

        $hasil = [];
        $total = count($rows);
        $done  = 0;

        foreach (array_chunk($rows, $chunkSize) as $group) {
            $commands = [];

            foreach ($group as $row) {
                $params = [
                    '=name'     => $row['name'],
                    '=password' => $row['password'],
                    '=profile'  => $row['profile'],
                ];

                if (! empty($row['server']))       $params['=server']            = $row['server'];
                if (! empty($row['limit_uptime'])) $params['=limit-uptime']      = $row['limit_uptime'];
                if (! empty($row['limit_bytes']))  $params['=limit-bytes-total'] = (string) $row['limit_bytes'];
                if (! empty($row['comment']))      $params['=comment']           = $row['comment'];

                $commands[] = ['cmd' => '/ip/hotspot/user/add', 'params' => $params];
            }

            $replies = $client->queryMany($commands, $chunkSize);

            foreach ($group as $i => $row) {
                $hasil[] = [
                    'name'  => $row['name'],
                    'ok'    => $replies[$i]['ok'] ?? false,
                    'error' => $replies[$i]['error'] ?? 'tidak ada balasan router',
                ];
            }

            $done += count($group);
            if ($progress) {
                $progress($done, $total);
            }
        }

        return $hasil;
    }

    public function removeUsers(Router $router, array $names): array
    {
        return $this->gateway->kunciTulis($router->slug, fn () => $this->removeUsersTerkunci($router, $names), self::TUNGGU_KUNCI, self::TTL_KUNCI);
    }

    private function removeUsersTerkunci(Router $router, array $names): array
    {
        if (! $names) {
            return ['removed' => 0, 'missing' => 0];
        }

        $client = $this->client($router);
        $wanted = array_flip(array_map('mb_strtolower', $names));

        $ids = [];
        foreach ($client->query('/ip/hotspot/user/print', ['=.proplist' => '.id,name']) as $row) {
            if (isset($wanted[mb_strtolower($row['name'] ?? '')])) {
                $ids[] = $row['.id'];
            }
        }

        $commands = array_map(
            fn (string $id) => ['cmd' => '/ip/hotspot/user/remove', 'params' => ['=.id' => $id]],
            $ids,
        );

        $removed = 0;
        foreach ($client->queryMany($commands, (int) config('voucher.sync_chunk', 25)) as $r) {
            if ($r['ok']) {
                $removed++;
            }
        }

        return ['removed' => $removed, 'missing' => count($names) - count($ids)];
    }

    public function scriptStatus(Router $router): array
    {
        $scriptName    = (string) config('voucher.script_name');
        $schedulerName = (string) config('voucher.scheduler_name');

        $client   = $this->client($router);
        $info     = $this->info($router);
        $profiles = $this->profiles($router);

        $script = collect($client->query('/system/script/print', ['?name' => $scriptName]))->first();

        $schedulers = $client->query('/system/scheduler/print', [
            '=.proplist' => '.id,name,interval,comment,disabled',
        ]);

        $ours   = collect($schedulers)->firstWhere('name', $schedulerName);
        $names  = collect($profiles)->pluck('name')->all();

        $legacy = collect($schedulers)
            ->filter(fn ($s) => in_array($s['name'] ?? '', $names, true))
            ->map(fn ($s) => ['id' => $s['.id'], 'name' => $s['name'], 'interval' => $s['interval'] ?? '-', 'disabled' => ($s['disabled'] ?? 'false') === 'true'])
            ->values()->all();

        $expected = $this->builder->loginScript($info['version']);

        return [
            'version'          => $info['version'],
            'iso_date'         => $info['iso_date'],
            'script_installed' => $script !== null,
            'script_current'   => $script !== null && trim((string) ($script['source'] ?? '')) === trim($expected),
            'scheduler'        => $ours ? ['name' => $ours['name'], 'interval' => $ours['interval'] ?? '-', 'disabled' => ($ours['disabled'] ?? 'false') === 'true'] : null,
            'legacy_schedulers' => $legacy,
            'profiles'         => $profiles,
        ];
    }

    public function pools(Router $router): array
    {
        return collect($this->client($router)->query('/ip/pool/print', ['=.proplist' => 'name']))
            ->pluck('name')->filter()->values()->all();
    }

    public function parentQueues(Router $router): array
    {
        return collect($this->client($router)->query('/queue/simple/print', ['?dynamic' => 'false', '=.proplist' => 'name']))
            ->pluck('name')->filter()->values()->all();
    }

    public function profileUsage(Router $router): array
    {
        $hitung = [];

        foreach ($this->client($router)->query('/ip/hotspot/user/print', ['=.proplist' => 'profile']) as $r) {
            $p = $r['profile'] ?? '';
            if ($p !== '') {
                $hitung[$p] = ($hitung[$p] ?? 0) + 1;
            }
        }

        return $hitung;
    }

    public static function namaProfilAman(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,30}$/', $name);
    }

    public static function profilTerlindungi(string $name): bool
    {
        return strcasecmp($name, 'default') === 0;
    }

    public function createProfile(Router $router, array $data): array
    {
        return $this->gateway->kunciTulis($router->slug, fn () => $this->createProfileTerkunci($router, $data), self::TUNGGU_KUNCI, self::TTL_KUNCI);
    }

    private function createProfileTerkunci(Router $router, array $data): array
    {
        $client = $this->client($router);
        $name   = $data['name'];

        $this->pastikanNamaBoleh($name);

        if (collect($this->profiles($router))->firstWhere('name', $name)) {
            throw new Exception("Profil \"{$name}\" sudah ada di router ini.");
        }

        $laporan = [];
        $this->pasangScriptBersama($router, $laporan);

        $client->query('/ip/hotspot/user/profile/add', $this->paramProfil($data, $name));

        $this->perbaruiScheduler($router);

        return [
            'aksi'    => 'dibuat',
            'name'    => $name,
            'script'  => $laporan['script'] ?? 'tidak berubah',
            'warning' => $laporan['script_policy_warning'] ?? null,
        ];
    }

    public function updateProfile(Router $router, string $original, array $data): array
    {
        return $this->gateway->kunciTulis($router->slug, fn () => $this->updateProfileTerkunci($router, $original, $data), self::TUNGGU_KUNCI, self::TTL_KUNCI);
    }

    private function updateProfileTerkunci(Router $router, string $original, array $data): array
    {
        $client = $this->client($router);
        $name   = $data['name'];

        $this->pastikanNamaBoleh($name);

        if (self::profilTerlindungi($original)) {
            throw new Exception('Profil "default" bawaan RouterOS tidak boleh diubah dari panel.');
        }

        $profil = collect($this->profiles($router))->firstWhere('name', $original);

        if (! $profil) {
            throw new Exception("Profil \"{$original}\" tidak ada lagi di router (mungkin sudah dihapus dari tempat lain).");
        }

        if ($name !== $original && collect($this->profiles($router))->firstWhere('name', $name)) {
            throw new Exception("Sudah ada profil bernama \"{$name}\" di router ini.");
        }

        $laporan = [];
        $this->pasangScriptBersama($router, $laporan);

        $client->query('/ip/hotspot/user/profile/set', [
            '=.id' => $profil['id'],
            ...$this->paramProfil($data, $name),
        ]);

        $yatim = 0;
        if ($name !== $original) {
            foreach ($client->query('/system/scheduler/print', ['?name' => $original, '=.proplist' => '.id']) as $s) {
                try {
                    $client->query('/system/scheduler/remove', ['=.id' => $s['.id']]);
                    $yatim++;
                } catch (Exception) {
                }
            }
        }

        $this->perbaruiScheduler($router);

        return [
            'aksi'    => 'diperbarui',
            'name'    => $name,
            'renamed' => $name !== $original,
            'scheduler_yatim_dicabut' => $yatim,
            'script'  => $laporan['script'] ?? 'tidak berubah',
            'warning' => $laporan['script_policy_warning'] ?? null,
        ];
    }

    public function deleteProfile(Router $router, string $name): array
    {
        return $this->gateway->kunciTulis($router->slug, fn () => $this->deleteProfileTerkunci($router, $name), self::TUNGGU_KUNCI, self::TTL_KUNCI);
    }

    private function deleteProfileTerkunci(Router $router, string $name): array
    {
        $client = $this->client($router);

        if (self::profilTerlindungi($name)) {
            throw new Exception('Profil "default" bawaan RouterOS tidak boleh dihapus.');
        }

        $profil = collect($this->profiles($router))->firstWhere('name', $name);

        if (! $profil) {
            throw new Exception("Profil \"{$name}\" tidak ada di router ini.");
        }

        $dipakai = $this->profileUsage($router)[$name] ?? 0;

        if ($dipakai > 0) {
            throw new Exception(
                "Profil \"{$name}\" masih dipakai {$dipakai} kartu. Hapus atau pindahkan kartunya dulu. "
                . 'Menghapus profil yang masih terpakai akan membuat kartu itu tidak bisa login.'
            );
        }

        $client->query('/ip/hotspot/user/profile/remove', ['=.id' => $profil['id']]);

        $this->perbaruiScheduler($router);

        $dicabut = 0;
        foreach ($client->query('/system/scheduler/print', ['?name' => $name, '=.proplist' => '.id,name']) as $s) {
            try {
                $client->query('/system/scheduler/remove', ['=.id' => $s['.id']]);
                $dicabut++;
            } catch (Exception) {
            }
        }

        return ['aksi' => 'dihapus', 'name' => $name, 'scheduler_yatim_dicabut' => $dicabut];
    }

    private function paramProfil(array $data, string $name): array
    {
        $mode    = (string) ($data['mode'] ?? 'off');
        $expmode = $mode === 'off' ? 'off' : $mode . (! empty($data['record']) ? 'c' : '');

        $params = [
            '=name'         => $name,
            '=shared-users' => (string) ($data['shared_users'] ?? 1),
            '=rate-limit'   => (string) ($data['rate_limit'] ?? ''),

            '=address-pool' => (string) ($data['address_pool'] ?: 'none'),
            '=parent-queue' => (string) ($data['parent_queue'] ?: 'none'),
            '=on-login'     => $this->builder->profileOnLogin($name, [
                'expmode'  => $expmode,
                'price'    => (int) ($data['price'] ?? 0),
                'validity' => (string) ($data['validity'] ?? ''),
                'sprice'   => (int) ($data['sprice'] ?? 0),
                'lock'     => (bool) ($data['lock'] ?? false),
                'record'   => (bool) ($data['record'] ?? false),
            ], (string) config('voucher.script_name')),
        ];

        if (! empty($data['idle_timeout'])) {
            $params['=idle-timeout'] = (string) $data['idle_timeout'];
        }

        return $params;
    }

    private function pastikanNamaBoleh(string $name): void
    {
        if (self::profilTerlindungi($name)) {
            throw new Exception('Nama "default" dipakai profil bawaan RouterOS, pilih nama lain.');
        }

        if (! self::namaProfilAman($name)) {
            throw new Exception(
                'Nama profil hanya boleh huruf, angka, titik, garis bawah, dan strip (maksimal 31 karakter, '
                . 'tanpa spasi). Nama ini ikut ditanam ke dalam script pengawas di router.'
            );
        }
    }

    private function pasangScriptBersama(Router $router, ?array &$laporan = null): void
    {
        $laporan ??= [];

        $client     = $this->client($router);
        $scriptName = (string) config('voucher.script_name');
        $source     = $this->builder->loginScript($this->info($router)['version']);

        $existing = collect($client->query('/system/script/print', ['?name' => $scriptName]))->first();

        if ($existing && trim((string) ($existing['source'] ?? '')) === trim($source)) {
            $laporan['script'] = 'tidak berubah';
            return;
        }

        $path = $existing ? '/system/script/set' : '/system/script/add';
        $base = $existing
            ? ['=.id' => $existing['.id'], '=source' => $source]
            : ['=name' => $scriptName, '=source' => $source, '=comment' => 'ZeroNet voucher generator'];

        try {
            $client->query($path, [...$base, '=policy' => 'read,write,test,policy']);
        } catch (Exception $e) {
            $client->query($path, $base);
            $laporan['script_policy_warning'] = 'Policy script tidak bisa disetel ('
                . $e->getMessage() . '). Pastikan akun API ada di grup dengan izin policy.';
        }

        $laporan['script'] = $existing ? 'diperbarui' : 'dipasang';
    }

    private function perbaruiScheduler(Router $router): string
    {
        $client        = $this->client($router);
        $schedulerName = (string) config('voucher.scheduler_name');

        $modes = [];
        foreach ($this->profiles($router) as $p) {
            if ($p['expmode'] === '' || $p['expmode'] === '0' || $p['validity'] === '') {
                continue;
            }
            if (! self::namaProfilAman($p['name'])) {
                continue;
            }
            $modes[$p['name']] = str_starts_with($p['expmode'], 'rem') ? 'rem' : 'ntf';
        }

        $sched = collect($client->query('/system/scheduler/print', ['?name' => $schedulerName]))->first();

        if (! $modes) {
            if ($sched) {
                $client->query('/system/scheduler/remove', ['=.id' => $sched['.id']]);
                return 'dicabut';
            }
            return 'tidak perlu';
        }

        $params = [
            '=name'     => $schedulerName,
            '=interval' => (string) config('voucher.scheduler_interval', '00:03:00'),
            '=on-event' => $this->builder->expireScript($modes),
            '=disabled' => 'no',
            '=comment'  => 'ZeroNet voucher (pengawas masa aktif semua profil)',
        ];

        $path = $sched ? '/system/scheduler/set' : '/system/scheduler/add';
        $base = $sched ? ['=.id' => $sched['.id']] : [];

        try {
            $client->query($path, [...$base, ...$params, '=policy' => 'read,write,test,policy']);
        } catch (Exception) {
            $client->query($path, [...$base, ...$params]);
        }

        return $sched ? 'diperbarui' : 'dipasang';
    }

    public function installScripts(Router $router, array $profileSettings, bool $dropLegacy = false): array
    {
        return $this->gateway->kunciTulis($router->slug, fn () => $this->installScriptsTerkunci($router, $profileSettings, $dropLegacy), self::TUNGGU_KUNCI, self::TTL_KUNCI);
    }

    private function installScriptsTerkunci(Router $router, array $profileSettings, bool $dropLegacy = false): array
    {
        $client     = $this->client($router);
        $scriptName = (string) config('voucher.script_name');

        $laporan = ['script' => 'tidak berubah', 'scheduler' => 'tidak berubah', 'profiles' => [], 'legacy_removed' => 0];

        $this->pasangScriptBersama($router, $laporan);

        foreach ($this->profiles($router) as $profile) {
            $name = $profile['name'];

            if (! isset($profileSettings[$name])) {
                continue;
            }

            if (! self::namaProfilAman($name)) {
                $laporan['profiles'][$name] = 'dilewati (nama tidak aman untuk script)';
                continue;
            }

            $set = $profileSettings[$name];

            $client->query('/ip/hotspot/user/profile/set', [
                '=.id'      => $profile['id'],
                '=on-login' => $this->builder->profileOnLogin($name, $set, $scriptName),
            ]);

            $laporan['profiles'][$name] = $set['expmode'] === 'off' ? 'dilepas' : $set['expmode'];
        }

        $laporan['scheduler'] = $this->perbaruiScheduler($router);

        if ($dropLegacy) {
            foreach ($this->scriptStatus($router)['legacy_schedulers'] as $old) {
                try {
                    $client->query('/system/scheduler/remove', ['=.id' => $old['id']]);
                    $laporan['legacy_removed']++;
                } catch (Exception) {
                }
            }
        }

        return $laporan;
    }
}
