<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class RouterPersiapanTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY_V6 = [
        'read'  => 'local,telnet,ssh,reboot,read,test,winbox,password,web,sniff,sensitive,api,romon,tikapp,!ftp,!write,!policy,!dude',
        'write' => 'local,telnet,ssh,reboot,read,write,test,winbox,password,web,sniff,sensitive,api,romon,tikapp,!ftp,!policy,!dude',
        'full'  => 'local,telnet,ssh,ftp,reboot,read,write,policy,test,winbox,password,web,sniff,sensitive,api,romon,tikapp,!dude',
    ];

    private const ONLOGIN_LAMA = ':put (",remc,5000,1d,5000,,Disable,"); :local mode "X"; {}';

    private ?RouterPalsu $palsu = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        File::deleteDirectory(storage_path('app/cutover/uji'));
        config(['services.mikrotik.sftp.host' => 'sftp.uji']);
    }

    protected function tearDown(): void
    {
        $this->palsu?->berhenti();
        File::deleteDirectory(storage_path('app/cutover/uji'));
        parent::tearDown();
    }

    private function siapkan(array $users, string $akun, string $sandi, array $grupTambahan = [], array $skenario = []): Router
    {
        $grup = array_map(fn ($n, $p) => ['name' => $n, 'policy' => $p], array_keys(self::POLICY_V6), self::POLICY_V6);

        $this->palsu = RouterPalsu::mulai([], $skenario, [
            '/user'                    => $users,
            '/user/group'              => [...$grup, ...$grupTambahan],
            '/ip/hotspot'              => [['name' => 'hotspot1']],
            '/ip/service'              => [['name' => 'api', 'port' => '8728', 'address' => '', 'disabled' => 'false']],
            '/ip/hotspot/user/profile' => [
                ['name' => 'default', 'on-login' => ''],
                ['name' => '5-JAM', 'on-login' => self::ONLOGIN_LAMA, 'on-logout' => ''],
            ],
            '/system/scheduler' => [['name' => '5-JAM', 'interval' => '2m30s', 'on-event' => ':put lama', 'disabled' => 'false']],
        ]);

        return Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => $akun, 'password' => $sandi,
        ]);
    }

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('x'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function akunRouter(string $nama): ?array
    {
        return collect($this->palsu->tabel('/user'))->firstWhere('name', $nama);
    }

    public function test_periksa_membaca_koneksi_akun_dan_script(): void
    {
        $this->siapkan([['name' => 'admin', 'group' => 'full', 'password' => 'rahasia', 'disabled' => 'false']], 'admin', 'rahasia');
        $this->actingAs($this->pengguna('admin'));

        $this->getJson(route('routers.siapkan.periksa', 'uji'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'koneksi' => ['identitas' => 'UJI', 'versi' => '6.49.19', 'tanggal_iso' => false, 'server' => ['hotspot1']],
                'akun'    => ['dipakai' => 'admin', 'grup' => 'full', 'bisa_kelola' => true, 'nama' => 'zeronet-api', 'ada' => false,
                    'policy' => ['api', 'read', 'write', 'policy', 'test', 'reboot', 'sensitive']],
                'script'  => ['terpasang' => false, 'pengawas' => null, 'lama' => ['5-JAM'], 'profil_lain' => 1,
                    'profil' => [['nama' => '5-JAM', 'jenis' => 'lama', 'mode' => 'rem', 'record' => true, 'validity' => '1d', 'price' => 5000, 'aman' => true]]],
                'selesai' => ['koneksi' => true, 'akun' => false, 'script' => false],
            ]);

        $this->assertSame('6.49.19', Router::where('slug', 'uji')->value('ros_version'));
    }

    public function test_buat_akun_panel_diuji_lalu_dipakai_tanpa_menyentuh_akun_lama(): void
    {
        $admin = ['name' => 'admin', 'group' => 'full', 'password' => 'rahasia', 'disabled' => 'false'];
        $this->siapkan([$admin], 'admin', 'rahasia');
        $this->actingAs($this->pengguna('admin'));

        $res = $this->postJson(route('routers.akun-panel', 'uji'))
            ->assertOk()
            ->assertJson(['success' => true, 'hasil' => ['akun' => 'dibuat', 'grup' => 'dibuat', 'sebelumnya' => 'admin', 'identitas' => 'UJI', 'tak_dikenal' => []]]);

        $router = Router::where('slug', 'uji')->first();
        $sandi  = (string) $router->password;

        $this->assertSame('zeronet-api', $router->username);
        $this->assertSame(24, strlen($sandi));
        $this->assertStringNotContainsString($sandi, $res->getContent());
        $this->assertSame(['group' => 'zeronet-api', 'password' => $sandi], array_intersect_key($this->akunRouter('zeronet-api'), ['group' => 1, 'password' => 1]));
        $this->assertSame($admin, array_diff_key($this->akunRouter('admin'), ['.id' => 1]));
        $this->assertSame('api,read,write,policy,test,reboot,sensitive', collect($this->palsu->tabel('/user/group'))->firstWhere('name', 'zeronet-api')['policy']);

        $log = ActivityLog::where('action', 'router_api_account')->sole();
        $this->assertStringNotContainsString($sandi, json_encode($log->toArray()));
        $this->assertSame('admin', $log->properties['akun_sebelumnya']);

        $this->getJson(route('routers.siapkan.periksa', 'uji'))
            ->assertOk()
            ->assertJson(['akun' => ['dipakai' => 'zeronet-api', 'ada' => true, 'bisa_kelola' => true], 'selesai' => ['akun' => true]]);
    }

    public function test_policy_yang_tidak_dikenal_versi_router_dilewati(): void
    {
        config(['services.mikrotik.akun_panel.policy' => ['api', 'read', 'write', 'policy', 'test', 'rest-api']]);
        $this->siapkan([['name' => 'admin', 'group' => 'full', 'password' => 'rahasia', 'disabled' => 'false']], 'admin', 'rahasia');
        $this->actingAs($this->pengguna('admin'));

        $this->postJson(route('routers.akun-panel', 'uji'))
            ->assertOk()
            ->assertJsonPath('hasil.policy', ['api', 'read', 'write', 'policy', 'test'])
            ->assertJsonPath('hasil.tak_dikenal', ['rest-api']);

        $this->assertSame('api,read,write,policy,test', collect($this->palsu->tabel('/user/group'))->firstWhere('name', 'zeronet-api')['policy']);
    }

    public function test_selama_backup_belum_lewat_sftp_akun_panel_diberi_hak_ftp(): void
    {
        config(['services.mikrotik.sftp.host' => '']);
        $this->siapkan([['name' => 'admin', 'group' => 'full', 'password' => 'rahasia', 'disabled' => 'false']], 'admin', 'rahasia');
        $this->actingAs($this->pengguna('admin'));

        $this->getJson(route('routers.siapkan.periksa', 'uji'))->assertOk()->assertJsonPath('akun.policy', ['api', 'read', 'write', 'policy', 'test', 'reboot', 'sensitive', 'ftp']);
        $this->postJson(route('routers.akun-panel', 'uji'))->assertOk();

        $this->assertSame('api,read,write,policy,test,reboot,sensitive,ftp', collect($this->palsu->tabel('/user/group'))->firstWhere('name', 'zeronet-api')['policy']);
        $this->assertSame(1, count(array_filter($this->palsu->tabel('/ip/service'), fn ($s) => $s['disabled'] === 'false')));
    }

    public function test_akun_baru_gagal_login_dicabut_lagi_dan_kredensial_panel_tetap(): void
    {
        $this->siapkan([['name' => 'admin', 'group' => 'full', 'password' => 'rahasia', 'disabled' => 'false']], 'admin', 'rahasia', [], ['tolak_akun' => ['zeronet-api']]);
        $this->actingAs($this->pengguna('admin'));

        $this->postJson(route('routers.akun-panel', 'uji'))
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'gagal dipakai login') && str_contains($e, 'dicabut lagi; panel tetap memakai akun admin'));

        $this->assertNull($this->akunRouter('zeronet-api'));
        $router = Router::where('slug', 'uji')->first();
        $this->assertSame(['admin', 'rahasia'], [$router->username, (string) $router->password]);
        $this->assertSame(1, ActivityLog::where('action', 'router_api_account_failed')->count());
    }

    public function test_ganti_password_akun_panel_dan_password_lama_kembali_saat_uji_gagal(): void
    {
        $grup = [['name' => 'zeronet-api', 'policy' => 'api,read,write,policy,test,reboot,sensitive']];
        $this->siapkan([['name' => 'zeronet-api', 'group' => 'zeronet-api', 'password' => 'lama123', 'disabled' => 'false']], 'zeronet-api', 'lama123', $grup);
        $this->actingAs($this->pengguna('admin'));

        $this->postJson(route('routers.akun-panel', 'uji'))
            ->assertOk()
            ->assertJson(['hasil' => ['akun' => 'password diganti', 'grup' => 'tidak berubah']]);

        $baru = (string) Router::where('slug', 'uji')->first()->password;
        $this->assertNotSame('lama123', $baru);
        $this->assertSame($baru, $this->akunRouter('zeronet-api')['password']);

        $this->palsu->skenario(['sandi_rusak' => ['zeronet-api']]);

        $this->postJson(route('routers.akun-panel', 'uji'))
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'Password lama dikembalikan'));

        $this->assertSame($baru, $this->akunRouter('zeronet-api')['password']);
        $this->assertSame($baru, (string) Router::where('slug', 'uji')->first()->password);
    }

    public function test_akun_tanpa_hak_kelola_user_ditolak_sebelum_menulis(): void
    {
        $this->siapkan([['name' => 'baca', 'group' => 'write', 'password' => 'rahasia', 'disabled' => 'false']], 'baca', 'rahasia');
        $this->actingAs($this->pengguna('admin'));

        $this->getJson(route('routers.siapkan.periksa', 'uji'))->assertOk()->assertJsonPath('akun.bisa_kelola', false);

        $this->postJson(route('routers.akun-panel', 'uji'))
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'tidak punya hak mengelola user'));

        $this->assertNull(collect($this->palsu->tabel('/user/group'))->firstWhere('name', 'zeronet-api'));
        $this->assertNull($this->akunRouter('zeronet-api'));
    }

    public function test_pasang_script_dari_wizard_menyimpan_snapshot_dulu(): void
    {
        $this->siapkan([['name' => 'admin', 'group' => 'full', 'password' => 'rahasia', 'disabled' => 'false']], 'admin', 'rahasia');
        $this->actingAs($this->pengguna('admin'));

        $res = $this->postJson(route('voucher-scripts.install', 'uji'), [
            'profiles'    => [['name' => '5-JAM', 'mode' => 'rem', 'record' => true, 'lock' => false, 'validity' => '1d', 'price' => 5000, 'sprice' => 5000]],
            'drop_legacy' => true,
            'snapshot'    => true,
        ])->assertOk()->assertJson(['success' => true, 'laporan' => ['legacy_removed' => 1]]);

        $berkas = storage_path('app/cutover/uji/' . $res->json('laporan.snapshot'));
        $this->assertFileExists($berkas);
        $this->assertSame(self::ONLOGIN_LAMA, collect(json_decode(File::get($berkas), true)['profil'])->firstWhere('name', '5-JAM')['on-login']);
        $this->assertStringContainsString('zeronet-onlogin', collect($this->palsu->tabel('/ip/hotspot/user/profile'))->firstWhere('name', '5-JAM')['on-login']);

        $this->getJson(route('routers.siapkan.periksa', 'uji'))
            ->assertOk()
            ->assertJson(['script' => ['terpasang' => true, 'terbaru' => true, 'lama' => [], 'profil' => [['nama' => '5-JAM', 'jenis' => 'panel']]], 'selesai' => ['script' => true]]);
    }

    public function test_halaman_hanya_admin_dan_tambah_router_mengarah_ke_wizard(): void
    {
        $this->siapkan([['name' => 'admin', 'group' => 'full', 'password' => 'rahasia', 'disabled' => 'false']], 'admin', 'rahasia');

        $this->actingAs($this->pengguna('admin'));
        $this->get(route('routers.siapkan', 'uji'))
            ->assertOk()
            ->assertSee('Siapkan UJI')->assertSee('Akun API panel')->assertSee('zeronet-api')
            ->assertDontSee('Mikhmon');

        $this->postJson(route('routers.store'), ['name' => 'Divisi Baru', 'host' => '10.9.9.9', 'port' => 8728, 'username' => 'admin', 'password' => 'x'])
            ->assertOk()
            ->assertJsonPath('redirect', route('routers.siapkan', 'divisi-baru'));

        $this->flushSession();
        $this->actingAs($this->pengguna('operator'));
        $this->get(route('routers.siapkan', 'uji'))->assertRedirect(route('dashboard'));
        $this->postJson(route('routers.akun-panel', 'uji'))->assertForbidden();
        $this->assertNull($this->akunRouter('zeronet-api'));
    }
}
