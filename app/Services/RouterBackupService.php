<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class RouterBackupService
{
    private const TUNGGU_DETIK = 20;

    public function __construct(private MikrotikService $mt) {}

    public function sftpAktif(): bool
    {
        return (string) config('services.mikrotik.sftp.host', '') !== '';
    }

    public function unduh(string $id): string
    {
        $cfg      = $this->mt->routerConfig($id);
        $nama     = 'bak-' . date('YmdHis');
        $file     = "{$nama}.backup";
        $password = (string) config('services.mikrotik.backup_password', '');

        $this->mt->q($id, '/system/backup/save', [
            '=name' => $nama,
            ...($password !== '' ? ['=password' => $password] : ['=dont-encrypt' => 'yes']),
        ]);

        try {
            $this->tungguFileRouter($id, $file);

            return $this->sftpAktif() ? $this->lewatSftp($id, $file) : $this->lewatFtp($cfg, $file);
        } finally {
            $this->hapusFileRouter($id, $file);
        }
    }

    private function lewatSftp(string $id, string $file): string
    {
        $s      = config('services.mikrotik.sftp');
        $remote = trim((string) $s['direktori_remote'], '/');
        $lokal  = rtrim((string) $s['direktori_lokal'], '/') . '/' . $file;

        try {
            $this->mt->q($id, '/tool/fetch', [
                '=upload'   => 'yes',
                '=mode'     => 'sftp',
                '=address'  => (string) $s['host'],
                '=port'     => (string) $s['port'],
                '=user'     => (string) $s['user'],
                '=password' => (string) $s['password'],
                '=src-path' => $file,
                '=dst-path' => $remote === '' ? $file : "{$remote}/{$file}",
            ]);

            return $this->tungguFileLokal($lokal);
        } finally {
            @unlink($lokal);
        }
    }

    private function lewatFtp(array $cfg, string $file): string
    {
        $ftp = @ftp_connect($cfg['host'], 21, 10);
        if (! $ftp) {
            throw new Exception('Tidak dapat membuka koneksi FTP. Pastikan FTP aktif: /ip/service set ftp disabled=no');
        }

        if (! @ftp_login($ftp, $cfg['user'], $cfg['pass'])) {
            ftp_close($ftp);
            throw new Exception('Login FTP gagal. Cek kredensial atau izin FTP user.');
        }

        ftp_pasv($ftp, true);

        $tmp = tempnam(sys_get_temp_dir(), 'mt-bak-');
        $ok  = @ftp_get($ftp, $tmp, $file, FTP_BINARY);
        ftp_close($ftp);

        if (! $ok) {
            @unlink($tmp);
            throw new Exception('Gagal mengunduh file backup dari router.');
        }

        $isi = file_get_contents($tmp);
        @unlink($tmp);

        return $isi;
    }

    private function tungguFileRouter(string $id, string $file): void
    {
        $batas   = microtime(true) + self::TUNGGU_DETIK;
        $sebelum = null;

        do {
            $size = (string) ($this->mt->q($id, '/file/print', ['?name' => $file])[0]['size'] ?? '');

            if ($size !== '' && $size !== '0' && $size === $sebelum) {
                return;
            }

            $sebelum = $size;
            usleep(500_000);
        } while (microtime(true) < $batas);

        throw new Exception('File backup belum siap di router setelah ' . self::TUNGGU_DETIK . ' detik.');
    }

    private function tungguFileLokal(string $lokal): string
    {
        $batas   = microtime(true) + self::TUNGGU_DETIK;
        $sebelum = -1;

        do {
            clearstatcache(true, $lokal);
            $size = is_file($lokal) ? (int) filesize($lokal) : 0;

            if ($size > 0 && $size === $sebelum && is_readable($lokal)) {
                return (string) file_get_contents($lokal);
            }

            $sebelum = $size;
            usleep(250_000);
        } while (microtime(true) < $batas);

        throw new Exception("Router melapor unggahan SFTP selesai tetapi {$lokal} tidak ada atau tidak terbaca oleh panel.");
    }

    private function hapusFileRouter(string $id, string $file): void
    {
        try {
            foreach ($this->mt->q($id, '/file/print', ['?name' => $file]) as $row) {
                $this->mt->q($id, '/file/remove', ['=.id' => $row['.id']]);
            }
        } catch (Exception $e) {
            Log::warning("File {$file} gagal dihapus dari router {$id}: " . $e->getMessage());
        }
    }
}
