<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class RouterKesehatanTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();

        $this->palsu = RouterPalsu::mulai([], [], [
            '/ip/hotspot/user/profile' => [
                ['name' => 'default', 'on-login' => ''],
                ['name' => '4-JAM', 'on-login' => ':put (",ntfc,5000,1d,5000,,Disable,"); :local zn [:parse [/system script get [/system script find where name="zeronet-onlogin"] source]]; $zn user=$user'],
                ['name' => '5-JAM', 'on-login' => ':put (",ntfc,5000,1d,0,,Disable,"); {}'],
            ],
            '/system/scheduler' => [
                ['name' => 'zeronet-expire', 'interval' => '3m', 'on-event' => '', 'disabled' => 'false'],
                ['name' => '5-JAM', 'interval' => '2m30s', 'on-event' => '', 'disabled' => 'false'],
            ],
            '/system/script' => [['name' => 'zeronet-onlogin', 'source' => ':put lama']],
        ]);
        Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);
    }

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        parent::tearDown();
    }

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('x'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_status_script_campuran_terbaca_dan_disimpan_sementara(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->getJson(route('routers.kesehatan', 'uji'))
            ->assertOk()
            ->assertJson([
                'success'          => true,
                'on_login'         => 'campuran',
                'profil'           => [['nama' => '4-JAM', 'jenis' => 'panel'], ['nama' => '5-JAM', 'jenis' => 'mikhmon']],
                'script_terpasang' => true,
                'script_terbaru'   => false,
                'pengawas_panel'   => ['name' => 'zeronet-expire', 'interval' => '3m', 'disabled' => false],
                'pengawas_mikhmon' => [['nama' => '5-JAM', 'aktif' => true]],
                'record_tersimpan' => 0,
            ]);

        $koneksi = $this->palsu->hitung()['koneksi'];
        $this->getJson(route('routers.kesehatan', 'uji'))->assertOk()->assertJsonPath('on_login', 'campuran');
        $this->assertSame($koneksi, $this->palsu->hitung()['koneksi']);

        $this->getJson(route('routers.kesehatan', ['router' => 'uji', 'segar' => 1]))->assertOk();
        $this->assertGreaterThan($koneksi, $this->palsu->hitung()['koneksi']);
    }

    public function test_operator_tidak_melihat_status_script(): void
    {
        $this->actingAs($this->pengguna('operator'));

        $this->getJson(route('routers.kesehatan', 'uji'))->assertForbidden();
        $this->get(route('routers.index'))->assertOk()->assertDontSee('/kesehatan');
    }

    public function test_admin_melihat_kartu_dengan_status_script_dan_ambang_disk(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->get(route('routers.index'))
            ->assertOk()
            ->assertSee(route('routers.kesehatan', 'uji'))
            ->assertSee('di bawah 10%', false);
    }
}
