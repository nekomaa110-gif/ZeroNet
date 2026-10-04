<?php

namespace Tests\Support;

use RuntimeException;

class RouterPalsu
{
    public int $port;

    private $proses;

    private string $berkas;

    private function __construct() {}

    public static function mulai(array $users = [], array $skenario = [], array $tabelLain = [], array $jam = []): self
    {
        $r = new self();

        $sementara = stream_socket_server('tcp://127.0.0.1:0');
        $r->port   = (int) substr(strrchr(stream_socket_get_name($sementara, false), ':'), 1);
        fclose($sementara);

        $r->berkas = tempnam(sys_get_temp_dir(), 'router-palsu-');
        file_put_contents($r->berkas, json_encode([
            'password'  => 'rahasia',
            'identitas' => 'UJI',
            'tabel'     => array_map(
                fn ($baris) => array_values(array_map(fn ($u, $i) => ['.id' => '*' . strtoupper(dechex(0x10000 + $i))] + $u, $baris, array_keys($baris))),
                ['/ip/hotspot/user' => $users] + $tabelLain + ['/system/script' => [], '/ip/hotspot/user/profile' => []],
            ),
            'jam'       => $jam,
            'seq'       => 0x20000,
            'skenario'  => $skenario,
            'hitung'    => ['add' => 0, 'koneksi' => 0],
        ]));

        $r->proses = proc_open(
            [PHP_BINARY, __DIR__ . '/router-palsu.php', (string) $r->port, $r->berkas],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipa,
        );

        if (trim((string) fgets($pipa[1])) !== 'siap') {
            throw new RuntimeException('Router palsu gagal dijalankan: ' . stream_get_contents($pipa[2]));
        }

        return $r;
    }

    public function config(array $ubah = []): array
    {
        return $ubah + ['host' => '127.0.0.1', 'port' => $this->port, 'user' => 'api', 'pass' => 'rahasia', 'timeout' => 5];
    }

    public function skenario(array $skenario): void
    {
        $state             = $this->state();
        $state['skenario'] = $skenario + $state['skenario'];
        file_put_contents($this->berkas, json_encode($state), LOCK_EX);
    }

    public function users(): array
    {
        return $this->tabel('/ip/hotspot/user');
    }

    public function tabel(string $jalur): array
    {
        return $this->state()['tabel'][$jalur] ?? [];
    }

    public function tambah(string $jalur, array $baris): void
    {
        $state = $this->state();
        foreach ($baris as $b) {
            $state['seq']++;
            $state['tabel'][$jalur][] = ['.id' => '*' . strtoupper(dechex($state['seq']))] + $b;
        }
        file_put_contents($this->berkas, json_encode($state), LOCK_EX);
    }

    public function hitung(): array
    {
        return $this->state()['hitung'];
    }

    public function berhenti(): void
    {
        if (is_resource($this->proses)) {
            proc_terminate($this->proses, 9);
            proc_close($this->proses);
        }
        @unlink($this->berkas);
    }

    private function state(): array
    {
        return json_decode((string) file_get_contents($this->berkas), true);
    }
}
