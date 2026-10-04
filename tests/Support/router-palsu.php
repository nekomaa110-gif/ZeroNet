<?php

[, $port, $berkas] = $argv;

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (! $server) {
    fwrite(STDERR, "gagal membuka port {$port}: {$errstr}\n");
    exit(1);
}

fwrite(STDOUT, "siap\n");

function muat(string $berkas): array
{
    return json_decode((string) file_get_contents($berkas), true);
}

function simpan(string $berkas, array $state): void
{
    $sementara = $berkas . '.' . getmypid();
    file_put_contents($sementara, json_encode($state));
    rename($sementara, $berkas);
}

function bacaByte($conn, int $n): ?string
{
    $buf = '';
    while (strlen($buf) < $n) {
        $c = fread($conn, $n - strlen($buf));
        if ($c === false || $c === '') {
            if (feof($conn)) {
                return null;
            }
            continue;
        }
        $buf .= $c;
    }

    return $buf;
}

function bacaPanjang($conn): ?int
{
    $b = bacaByte($conn, 1);
    if ($b === null) {
        return null;
    }
    $b = ord($b);
    if (($b & 0x80) === 0) {
        return $b;
    }
    if (($b & 0xC0) === 0x80) {
        return (($b & 0x3F) << 8) | ord(bacaByte($conn, 1));
    }
    if (($b & 0xE0) === 0xC0) {
        return (($b & 0x1F) << 16) | unpack('n', bacaByte($conn, 2))[1];
    }
    if (($b & 0xF0) === 0xE0) {
        return (($b & 0x0F) << 24) | unpack('N', "\0" . bacaByte($conn, 3))[1];
    }

    return unpack('N', bacaByte($conn, 4))[1];
}

function bacaKalimat($conn): ?array
{
    $kata = [];
    while (true) {
        $n = bacaPanjang($conn);
        if ($n === null) {
            return null;
        }
        if ($n === 0) {
            return $kata;
        }
        $kata[] = bacaByte($conn, $n);
    }
}

function kodePanjang(int $n): string
{
    if ($n < 0x80) {
        return chr($n);
    }
    if ($n < 0x4000) {
        return pack('n', $n | 0x8000);
    }
    if ($n < 0x200000) {
        return substr(pack('N', $n | 0xC00000), 1);
    }

    return pack('N', $n | 0xE0000000);
}

function kirim($conn, array $kata, ?string $tag): void
{
    if ($tag !== null) {
        $kata[] = '.tag=' . $tag;
    }
    $buf = '';
    foreach ($kata as $k) {
        $buf .= kodePanjang(strlen($k)) . $k;
    }
    fwrite($conn, $buf . "\0");
}

function layani($conn, string $berkas): void
{
    $masuk = false;

    while (($kalimat = bacaKalimat($conn)) !== null) {
        if (! $kalimat) {
            continue;
        }

        $state = muat($berkas);
        $sk    = $state['skenario'];
        $cmd   = $kalimat[0];
        $param = [];
        $cari  = [];
        $tag   = null;

        foreach (array_slice($kalimat, 1) as $k) {
            if (str_starts_with($k, '.tag=')) {
                $tag = substr($k, 5);
            } elseif (str_starts_with($k, '=')) {
                [$a, $b] = array_pad(explode('=', substr($k, 1), 2), 2, '');
                $param[$a] = $b;
            } elseif (str_starts_with($k, '?')) {
                [$a, $b] = array_pad(explode('=', substr($k, 1), 2), 2, '');
                $cari[$a] = $b;
            }
        }

        if (! empty($sk['tunda_ms'])) {
            usleep((int) $sk['tunda_ms'] * 1000);
        }

        if ($cmd === '/login') {
            $akun = null;
            foreach ($state['tabel']['/user'] ?? [] as $u) {
                if (($u['name'] ?? null) === ($param['name'] ?? '')) {
                    $akun = $u;
                }
            }
            $sandi = $akun && array_key_exists('password', $akun) ? $akun['password'] : $state['password'];
            $tolak = ! empty($sk['tolak_login'])
                || in_array($param['name'] ?? '', (array) ($sk['tolak_akun'] ?? []), true)
                || ($akun && ($akun['disabled'] ?? 'false') === 'true');

            if ($tolak || ($param['password'] ?? '') !== $sandi) {
                kirim($conn, ['!trap', '=message=invalid user name or password (6)'], null);
                kirim($conn, ['!done'], null);

                return;
            }
            $masuk = true;
            kirim($conn, ['!done'], null);
            continue;
        }

        if (! $masuk) {
            return;
        }

        if (! empty($sk['diam'])) {
            continue;
        }

        [$jalur, $aksi] = [substr($cmd, 0, (int) strrpos($cmd, '/')), substr($cmd, (int) strrpos($cmd, '/') + 1)];
        $state['tabel'][$jalur] ??= null;

        if ($jalur === '/user' && in_array($aksi, ['add', 'set'], true) && isset($param['password'])) {
            $sasaran = $param['name'] ?? null;
            foreach ($state['tabel']['/user'] ?? [] as $u) {
                if ($aksi === 'set' && $u['.id'] === ($param['.id'] ?? '')) {
                    $sasaran = $u['name'] ?? null;
                }
            }
            $rusak = (array) ($sk['sandi_rusak'] ?? []);
            if (in_array($sasaran, $rusak, true)) {
                $param['password'] .= '-rusak';
                $state['skenario']['sandi_rusak'] = array_values(array_diff($rusak, [$sasaran]));
            }
        }

        if ($state['tabel'][$jalur] === null) {
            unset($state['tabel'][$jalur]);
        } elseif ($aksi === 'add') {
            $state['hitung']['add'] = ($state['hitung']['add'] ?? 0) + 1;
            $profil = array_column($state['tabel']['/ip/hotspot/user/profile'] ?? [], 'name');

            if ($jalur === '/ip/hotspot/user' && $profil && isset($param['profile']) && ! in_array($param['profile'], $profil, true)) {
                simpan($berkas, $state);
                kirim($conn, ['!trap', '=message=input does not match any value of profile'], $tag);
                kirim($conn, ['!done'], $tag);
                continue;
            }
            $ada = array_filter($state['tabel'][$jalur], fn ($u) => ($u['name'] ?? null) === ($param['name'] ?? ''));

            if ($ada && empty($sk['kembar_boleh'])) {
                simpan($berkas, $state);
                kirim($conn, ['!trap', '=message=failure: already have user with this name for this server'], $tag);
                kirim($conn, ['!done'], $tag);
                continue;
            }

            $state['seq'] = ($state['seq'] ?? 0) + 1;
            $id           = '*' . strtoupper(dechex($state['seq']));
            $state['tabel'][$jalur][] = ['.id' => $id] + $param;
            simpan($berkas, $state);

            if (! empty($sk['putus_setelah_add']) && $state['hitung']['add'] >= (int) $sk['putus_setelah_add']) {
                $state['skenario']['putus_setelah_add'] = null;
                simpan($berkas, $state);

                return;
            }

            kirim($conn, ['!done', '=ret=' . $id], $tag);
            continue;
        } elseif ($aksi === 'print') {
            $state['hitung']['print'][$jalur] = ($state['hitung']['print'][$jalur] ?? 0) + (array_key_exists('count-only', $param) ? 0 : 1);
            $state['hitung']['hitung'][$jalur] = ($state['hitung']['hitung'][$jalur] ?? 0) + (array_key_exists('count-only', $param) ? 1 : 0);
            simpan($berkas, $state);

            $baris = array_values(array_filter($state['tabel'][$jalur], function ($u) use ($cari) {
                foreach ($cari as $k => $v) {
                    if (($u[$k] ?? '') !== $v) {
                        return false;
                    }
                }

                return true;
            }));

            if (array_key_exists('count-only', $param)) {
                kirim($conn, ['!done', '=ret=' . count($baris)], $tag);
                continue;
            }

            $props = isset($param['.proplist']) ? explode(',', $param['.proplist']) : null;
            foreach ($baris as $u) {
                $kata = ['!re'];
                foreach ($u as $k => $v) {
                    if ($props === null || in_array($k, $props, true)) {
                        $kata[] = "={$k}={$v}";
                    }
                }
                kirim($conn, $kata, $tag);
            }
            kirim($conn, ['!done'], $tag);
            continue;
        } elseif ($aksi === 'set') {
            $id = $param['.id'] ?? '';
            $ketemu = false;
            foreach ($state['tabel'][$jalur] as &$baris) {
                if ($baris['.id'] === $id) {
                    $baris = array_diff_key($param, ['.id' => 1]) + $baris;
                    $ketemu = true;
                }
            }
            unset($baris);
            simpan($berkas, $state);
            if (! $ketemu) {
                kirim($conn, ['!trap', '=message=no such item'], $tag);
            }
            kirim($conn, ['!done'], $tag);
            continue;
        } elseif ($aksi === 'remove') {
            $ids = explode(',', $param['.id'] ?? '');
            $state['hitung']['remove'][$jalur] = ($state['hitung']['remove'][$jalur] ?? 0) + count($ids);
            $state['tabel'][$jalur] = array_values(array_filter($state['tabel'][$jalur], fn ($u) => ! in_array($u['.id'], $ids, true)));
            simpan($berkas, $state);
            kirim($conn, ['!done'], $tag);
            continue;
        }

        if ($cmd === '/system/backup/save') {
            $state['seq'] = ($state['seq'] ?? 0) + 1;
            $state['tabel']['/file'][] = ['.id' => '*' . strtoupper(dechex($state['seq'])), 'name' => ($param['name'] ?? 'backup') . '.backup', 'size' => '34567'];
            simpan($berkas, $state);
            kirim($conn, ['!done'], $tag);
            continue;
        }

        if ($cmd === '/tool/fetch') {
            $state['hitung']['fetch'][] = array_diff_key($param, ['password' => 1]);
            simpan($berkas, $state);
            $ada = in_array($param['src-path'] ?? '', array_column($state['tabel']['/file'] ?? [], 'name'), true);
            if (! $ada || ($param['password'] ?? '') !== ($sk['sftp_password'] ?? '')) {
                kirim($conn, ['!trap', '=message=failure: ' . ($ada ? 'SSH authentication failed' : 'file not found')], $tag);
                kirim($conn, ['!done'], $tag);
                continue;
            }
            @file_put_contents(($sk['sftp_root'] ?? sys_get_temp_dir()) . '/' . basename((string) ($param['dst-path'] ?? 'x')), 'ISI-BACKUP-PALSU');
            kirim($conn, ['!re', '=status=finished'], $tag);
            kirim($conn, ['!done'], $tag);
            continue;
        }

        if ($cmd === '/system/identity/print') {
            kirim($conn, ['!re', '=name=' . $state['identitas']], $tag);
            kirim($conn, ['!done'], $tag);
            continue;
        }

        if ($cmd === '/system/resource/print') {
            kirim($conn, ['!re', '=version=6.49.19 (long-term)', '=uptime=1d2h', '=board-name=UJI', '=cpu-load=5',
                '=total-memory=67108864', '=free-memory=33554432', '=total-hdd-space=16777216', '=free-hdd-space=2516582'], $tag);
            kirim($conn, ['!done'], $tag);
            continue;
        }

        if ($cmd === '/system/clock/print') {
            kirim($conn, ['!re', '=date=' . ($state['jam']['date'] ?? 'sep/26/2026'), '=time=' . ($state['jam']['time'] ?? '20:00:00'),
                '=gmt-offset=' . ($state['jam']['gmt-offset'] ?? '+07:00')], $tag);
            kirim($conn, ['!done'], $tag);
            continue;
        }

        kirim($conn, ['!trap', '=message=no such command prefix'], $tag);
        kirim($conn, ['!done'], $tag);
    }
}

while (true) {
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
    }

    $conn = @stream_socket_accept($server, 1);
    if (! $conn) {
        continue;
    }

    $state = muat($berkas);
    $state['hitung']['koneksi'] = ($state['hitung']['koneksi'] ?? 0) + 1;
    simpan($berkas, $state);

    $anak = pcntl_fork();

    if ($anak === 0) {
        fclose($server);
        layani($conn, $berkas);
        @fclose($conn);
        exit(0);
    }

    if ($anak === -1) {
        layani($conn, $berkas);
    }

    @fclose($conn);
}
