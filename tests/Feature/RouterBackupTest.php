<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\RouterBackupService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class RouterBackupTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    private string $vps;

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        File::deleteDirectory($this->vps);
        parent::tearDown();
    }

    private function siapkan(bool $sftp, string $sandiRouter = 'sandi-sftp'): void
    {
        $this->vps = sys_get_temp_dir() . '/uji-sftp-' . uniqid();
        File::ensureDirectoryExists($this->vps);

        $this->palsu = RouterPalsu::mulai([], ['sftp_password' => $sandiRouter, 'sftp_root' => $this->vps]);
        Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);
        MikrotikService::forgetRouterCache();

        config(['services.mikrotik.sftp' => [
            'host' => $sftp ? '127.0.0.1' : '', 'port' => 22, 'user' => 'zn-backup', 'password' => 'sandi-sftp',
            'direktori_remote' => 'incoming', 'direktori_lokal' => $this->vps,
        ]]);
    }

    public function test_backup_lewat_sftp_membaca_isi_lalu_membersihkan_router_dan_vps(): void
    {
        $this->siapkan(true);

        $isi = app(RouterBackupService::class)->unduh('uji');

        $this->assertSame('ISI-BACKUP-PALSU', $isi);
        $this->assertSame([], $this->palsu->tabel('/file'));
        $this->assertSame([], glob($this->vps . '/*'));

        $fetch = $this->palsu->hitung()['fetch'][0];
        $this->assertSame(['yes', 'sftp', '127.0.0.1', 'zn-backup'], [$fetch['upload'], $fetch['mode'], $fetch['address'], $fetch['user']]);
        $this->assertMatchesRegularExpression('#^incoming/bak-\d{14}\.backup$#', $fetch['dst-path']);
    }

    public function test_sftp_gagal_login_tetap_menghapus_file_backup_di_router(): void
    {
        $this->siapkan(true, 'sandi-lain');

        try {
            app(RouterBackupService::class)->unduh('uji');
            $this->fail('seharusnya gagal');
        } catch (Exception $e) {
            $this->assertStringContainsString('SSH authentication failed', $e->getMessage());
        }

        $this->assertSame([], $this->palsu->tabel('/file'));
    }

    public function test_tanpa_konfigurasi_sftp_tetap_memakai_jalur_ftp_lama(): void
    {
        $this->siapkan(false);

        try {
            app(RouterBackupService::class)->unduh('uji');
            $this->fail('seharusnya gagal karena tidak ada FTP di 127.0.0.1');
        } catch (Exception $e) {
            $this->assertStringContainsString('FTP', $e->getMessage());
        }

        $this->assertArrayNotHasKey('fetch', $this->palsu->hitung());
        $this->assertSame([], $this->palsu->tabel('/file'));
    }
}
