<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Services\PackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class PaketTest extends TestCase
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
                ['name' => 'Member', 'rate-limit' => '4M/4M', 'shared-users' => '1', 'on-login' => ''],
                ['name' => '4-JAM', 'rate-limit' => '2M/2M', 'shared-users' => '1', 'on-login' => ':put (",ntfc,5000,1d,6000,,Enable,"); {}'],
            ],
        ]);
        Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);

        $svc = app(PackageService::class);
        $svc->create(['groupname' => 'Member', 'display_name' => 'Paket Bulanan', 'is_active' => 1, 'is_public' => 1, 'price' => 200000, 'validity_days' => 30, 'attributes' => [
            ['attribute' => 'Mikrotik-Group', 'op' => ':=', 'value' => 'Member', 'target_table' => 'radgroupreply'],
            ['attribute' => 'Simultaneous-Use', 'op' => ':=', 'value' => '1', 'target_table' => 'radgroupcheck'],
        ]]);
        $svc->create(['groupname' => 'harian-4-jam', 'display_name' => 'Paket Harian', 'is_active' => 1, 'attributes' => [
            ['attribute' => 'Mikrotik-Group', 'op' => ':=', 'value' => '4-Jam', 'target_table' => 'radgroupreply'],
        ]]);
        DB::table('radgroupreply')->insert(['groupname' => 'grup-lama', 'attribute' => 'Mikrotik-Group', 'op' => ':=', 'value' => 'lama']);
        DB::table('radusergroup')->insert([['username' => 'a1', 'groupname' => 'Member', 'priority' => 1], ['username' => 'a2', 'groupname' => 'Member', 'priority' => 1]]);
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

    public function test_daftar_paket_memuat_profil_router_pelanggan_website_dan_grup_di_luar_panel(): void
    {
        $paket = app(PackageService::class)->all()->keyBy('groupname');

        $this->assertSame(['Member', 'harian-4-jam', 'grup-lama'], $paket->keys()->all());
        $this->assertSame(['Paket Bulanan', 'Member', '1', 2, true], [$paket['Member']['nama'], $paket['Member']['profil'], $paket['Member']['perangkat'], $paket['Member']['user_count'], $paket['Member']['is_public']]);
        $this->assertSame('4-Jam', $paket['harian-4-jam']['profil']);
        $this->assertTrue($paket['grup-lama']['is_legacy']);
        $this->assertTrue($paket['grup-lama']['is_active']);

        $this->actingAs($this->pengguna('admin'));
        $this->get(route('packages.index'))->assertOk()
            ->assertSee('Paket Bulanan')->assertSee('harian-4-jam')->assertSee('Tambah profil')
            ->assertDontSee('Paling banyak dipakai')->assertDontSee('Paket / Profile');

        $this->flushSession();
        $this->actingAs($this->pengguna('operator'));
        $this->get(route('packages.index'))->assertOk()->assertDontSee('Tambah profil');
    }

    public function test_simpan_radius_mengatur_grup_dan_perangkat_tanpa_menghapus_atribut_lain(): void
    {
        DB::table('package_attributes')->insert([
            'package_id' => DB::table('packages')->where('groupname', 'Member')->value('id'),
            'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => '4M/4M', 'target_table' => 'radgroupreply', 'sort_order' => 9,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->pengguna('admin'));

        $this->postJson(route('packages.radius'), [
            'groupname' => 'Member', 'profil' => 'Member-Baru', 'perangkat' => 2, 'display_name' => 'Paket Bulanan',
            'is_active' => true, 'is_public' => true, 'price' => 200000, 'validity_days' => 30, 'sort_order' => 3,
        ])->assertOk()->assertJsonPath('success', true);

        $reply = DB::table('radgroupreply')->where('groupname', 'Member')->pluck('value', 'attribute')->all();
        $check = DB::table('radgroupcheck')->where('groupname', 'Member')->pluck('value', 'attribute')->all();
        $this->assertSame(['Mikrotik-Group' => 'Member-Baru', 'Mikrotik-Rate-Limit' => '4M/4M'], array_intersect_key($reply, ['Mikrotik-Group' => 1, 'Mikrotik-Rate-Limit' => 1]));
        $this->assertSame('2', $check['Simultaneous-Use']);
        $this->assertArrayNotHasKey('Auth-Type', $check);
    }

    public function test_grup_di_luar_panel_diambil_alih_tanpa_kehilangan_atribut(): void
    {
        DB::table('radgroupcheck')->insert(['groupname' => 'grup-lama', 'attribute' => 'Idle-Timeout', 'op' => ':=', 'value' => '300']);
        $this->actingAs($this->pengguna('admin'));

        $this->postJson(route('packages.radius'), ['groupname' => 'grup-lama', 'profil' => 'Member', 'is_active' => true])->assertOk();

        $this->assertTrue(DB::table('packages')->where('groupname', 'grup-lama')->exists());
        $this->assertSame('Member', DB::table('radgroupreply')->where('groupname', 'grup-lama')->where('attribute', 'Mikrotik-Group')->value('value'));
        $this->assertSame('300', DB::table('radgroupcheck')->where('groupname', 'grup-lama')->where('attribute', 'Idle-Timeout')->value('value'));
    }

    public function test_paket_baru_dengan_nama_kembar_ditolak_dan_operator_tidak_boleh_menyimpan(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->postJson(route('packages.radius'), ['groupname' => 'Member', 'baru' => true, 'profil' => 'Member'])
            ->assertStatus(422)->assertJsonPath('errors.groupname.0', 'Nama paket sudah dipakai.');

        $this->flushSession();
        $this->actingAs($this->pengguna('operator'));
        $this->postJson(route('packages.radius'), ['groupname' => 'baru', 'profil' => 'Member'])->assertForbidden();
    }

    public function test_profil_router_dibaca_sekali_lalu_disimpan_sementara(): void
    {
        $this->actingAs($this->pengguna('operator'));

        $this->getJson(route('packages.router-profiles', 'uji'))->assertOk()
            ->assertJsonPath('profiles.0.name', 'Member')->assertJsonPath('profiles.0.mode', 'off')
            ->assertJsonPath('profiles.1.name', '4-JAM')->assertJsonPath('profiles.1.mode', 'ntf')
            ->assertJsonPath('profiles.1.record', true)->assertJsonPath('profiles.1.validity', '1d')
            ->assertJsonPath('profiles.1.price', 5000)->assertJsonPath('profiles.1.sprice', 6000)->assertJsonPath('profiles.1.lock', true);
        $koneksi = $this->palsu->hitung()['koneksi'];

        $this->getJson(route('packages.router-profiles', 'uji'))->assertOk();
        $this->assertSame($koneksi, $this->palsu->hitung()['koneksi']);

        $this->getJson(route('packages.router-profiles', ['router' => 'uji', 'segar' => 1]))->assertOk();
        $this->assertGreaterThan($koneksi, $this->palsu->hitung()['koneksi']);
    }

    public function test_router_mati_dijawab_503_tanpa_detail_koneksi(): void
    {
        $this->palsu->berhenti();
        Router::where('slug', 'uji')->update(['port' => 1]);
        $this->actingAs($this->pengguna('admin'));

        $this->getJson(route('packages.router-profiles', 'uji'))->assertStatus(503)->assertExactJson(['success' => false, 'error' => 'UJI tidak terjangkau.']);
    }
}
