<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Router;
use App\Services\RouterScriptSnapshot;
use App\Services\VoucherRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class RouterCutoverTest extends TestCase
{
    use RefreshDatabase;

    private const ON_LOGIN_MIKHMON = ':put (",ntfc,5000,1d,0,,Disable,"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]}';

    private const ON_EVENT_MIKHMON = ':local dateint do={}; :foreach i in [/ip hotspot user find where profile="5-JAM"] do={}';

    private RouterPalsu $palsu;

    private Router $router;

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        File::deleteDirectory(storage_path('app/cutover/uji'));
        parent::tearDown();
    }

    private function siapkan(): void
    {
        $this->palsu = RouterPalsu::mulai([
            ['name' => 'a1', 'password' => 'x', 'profile' => '5-JAM', 'comment' => 'up-001-09.26.26-stok1'],
            ['name' => 'a2', 'password' => 'x', 'profile' => '5-JAM', 'comment' => 'sep/20/2026 10:00:00'],
            ['name' => 'a3', 'password' => 'x', 'profile' => '5-JAM', 'comment' => '2026-09-20 10:00:00'],
            ['name' => 'admin', 'password' => 'x', 'profile' => 'default', 'comment' => ''],
        ], [], [
            '/ip/hotspot/user/profile' => [
                ['name' => 'default', 'on-login' => ''],
                ['name' => '5-JAM', 'on-login' => self::ON_LOGIN_MIKHMON, 'rate-limit' => '2M/5M', 'shared-users' => '1'],
            ],
            '/system/scheduler' => [
                ['name' => '5-JAM', 'start-date' => 'sep/01/2026', 'start-time' => '10:00:00', 'interval' => '00:04:00', 'on-event' => self::ON_EVENT_MIKHMON, 'policy' => 'ftp,reboot,read,write,policy,test,password,sniff,sensitive,romon', 'disabled' => 'false', 'comment' => 'Monitor Profile 5-JAM'],
                ['name' => 'backup-harian', 'start-date' => 'sep/01/2026', 'start-time' => '03:00:00', 'interval' => '1d', 'on-event' => '/system backup save', 'policy' => 'read,write', 'disabled' => 'false'],
            ],
            '/system/script' => [
                ['name' => 'sep/20/2026-|-10:00:00-|-a2-|-5000-|-10.10.10.9-|-AA:BB:CC:DD:EE:01-|-1d-|-5-JAM-|-up-001', 'owner' => 'sep2026', 'source' => 'sep/20/2026', 'comment' => 'mikhmon'],
            ],
            '/ip/service' => [['name' => 'api', 'port' => '8728', 'address' => '', 'disabled' => 'false']],
            '/user'       => [['name' => 'mikhmon', 'group' => 'full', 'address' => '', 'disabled' => 'false']],
            '/user/group' => [['name' => 'full', 'policy' => 'local,telnet,ssh,ftp,reboot,read,write,policy,test,winbox,password,web,sniff,sensitive,api']],
        ]);

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);
    }

    private function potret(): array
    {
        return [
            'profil'    => array_column($this->palsu->tabel('/ip/hotspot/user/profile'), 'on-login', 'name'),
            'scheduler' => array_map(fn ($s) => array_intersect_key($s, array_flip(['name', 'interval', 'on-event', 'policy', 'start-date', 'start-time', 'disabled', 'comment'])), $this->palsu->tabel('/system/scheduler')),
            'script'    => array_column($this->palsu->tabel('/system/script'), 'name'),
        ];
    }

    public function test_snapshot_hanya_baca_dan_merangkum_yang_dibutuhkan_rollback(): void
    {
        $this->siapkan();
        $sebelum = $this->potret();

        ['berkas' => $berkas, 'data' => $d] = app(RouterScriptSnapshot::class)->simpan($this->router);

        $this->assertSame($sebelum, $this->potret());
        $this->assertSame(hash_file('sha256', $berkas), trim(file_get_contents("{$berkas}.sha256")));
        $this->assertSame(1, $d['record']);
        $this->assertSame([], $d['script']);
        $this->assertSame(['batch' => 1, 'stempel_lama' => 1, 'stempel_iso' => 1, 'kosong' => 1, 'lain' => 0], $d['stempel']);
        $this->assertSame(['5-JAM', 'backup-harian'], array_column($d['scheduler'], 'name'));
    }

    public function test_berkas_lain_di_folder_snapshot_tidak_dianggap_snapshot_terbaru(): void
    {
        $this->siapkan();
        ['berkas' => $berkas] = app(RouterScriptSnapshot::class)->simpan($this->router);
        File::put(dirname($berkas) . '/cutover-29991231-235959.json', '{}');

        $this->assertSame($berkas, app(RouterScriptSnapshot::class)->terbaru($this->router));
    }

    public function test_snapshot_menandai_scheduler_sisa_on_login(): void
    {
        $this->siapkan();
        $this->palsu->tambah('/system/scheduler', [
            ['name' => 'a1', 'start-date' => '2026-09-09', 'start-time' => '11:57:21', 'interval' => '1d', 'on-event' => '', 'disabled' => 'false'],
            ['name' => 'zn-a2', 'start-date' => '2026-09-09', 'start-time' => '12:00:00', 'interval' => '1d', 'on-event' => '', 'disabled' => 'false'],
        ]);

        $d = app(RouterScriptSnapshot::class)->ambil($this->router);
        $this->assertSame(['a1', 'zn-a2'], $d['scheduler_sisa']);

        $this->artisan('router:simpan-script', ['--router' => 'uji'])
            ->expectsOutputToContain('scheduler sisa on-login')
            ->assertSuccessful();
    }

    public function test_cutover_lalu_rollback_mengembalikan_on_login_dan_scheduler_persis(): void
    {
        $this->siapkan();
        $sebelum = $this->potret();

        $this->artisan('router:simpan-script', ['--router' => 'uji'])->assertSuccessful();

        app(VoucherRouterService::class)->installScripts($this->router, [
            '5-JAM' => ['expmode' => 'ntfc', 'record' => true, 'lock' => false, 'validity' => '1d', 'price' => 5000, 'sprice' => 0],
        ], true);

        $sesudah = $this->potret();
        $this->assertStringContainsString((string) config('voucher.script_name'), $sesudah['profil']['5-JAM']);
        $this->assertNotContains('5-JAM', array_column($sesudah['scheduler'], 'name'));
        $this->assertContains((string) config('voucher.scheduler_name'), array_column($sesudah['scheduler'], 'name'));

        $this->artisan('router:pulihkan-script', ['--router' => 'uji'])
            ->expectsOutputToContain('tidak ada yang ditulis')
            ->assertSuccessful();
        $this->assertSame($sesudah, $this->potret());

        $this->artisan('router:pulihkan-script', ['--router' => 'uji', '--jalan' => true])
            ->expectsOutputToContain('Terverifikasi')
            ->assertSuccessful();

        $pulih = $this->potret();
        $this->assertSame($sebelum['profil'], $pulih['profil']);
        $this->assertEqualsCanonicalizing($sebelum['scheduler'], $pulih['scheduler']);
        $this->assertSame($sebelum['script'], $pulih['script']);
        $this->assertSame(1, ActivityLog::where('action', 'router_pulihkan_script')->count());
    }

    public function test_snapshot_yang_diubah_atau_milik_router_lain_ditolak(): void
    {
        $this->siapkan();
        $svc = app(RouterScriptSnapshot::class);
        ['berkas' => $berkas] = $svc->simpan($this->router);

        $data = json_decode(file_get_contents($berkas), true);
        $data['profil'][1]['on-login'] = 'diubah';
        file_put_contents($berkas, json_encode($data));

        $this->artisan('router:pulihkan-script', ['--router' => 'uji', '--berkas' => $berkas])
            ->expectsOutputToContain('hash tidak cocok')
            ->assertFailed();

        $lain = $this->router->replicate()->fill(['slug' => 'lain', 'name' => 'LAIN']);
        $lain->save();
        $snap = $svc->baca($svc->simpan($this->router)['berkas']);

        $this->expectExceptionMessage('bukan lain');
        $svc->rencanaPulih($lain, $snap);
    }
}
