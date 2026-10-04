<?php

namespace Tests\Feature;

use App\Console\Commands\MikrotikPoll;
use App\Models\ActivityLog;
use App\Models\Router;
use App\Models\User;
use App\Services\LoadBalanceService;
use App\Services\MikrotikService;
use App\Services\RouterGateway;
use App\Services\RouterSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class HotspotMonitorTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->palsu?->berhenti();
        parent::tearDown();
    }

    private function siapkan(): void
    {
        $this->palsu = RouterPalsu::mulai([], [], [
            '/ip/hotspot/active' => [['user' => 'u1', 'mac-address' => 'AA:BB:CC:00:00:01', 'address' => '10.10.10.5', 'uptime' => '1h2m']],
            '/ip/hotspot/host'   => [['mac-address' => 'AA:BB:CC:00:00:01', 'address' => '10.10.10.5', 'authorized' => 'true']],
            '/ip/hotspot/cookie' => [['user' => 'u1', 'mac-address' => 'AA:BB:CC:00:00:01', 'expires-in' => '6d']],
        ]);

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);
        MikrotikService::forgetRouterCache();
    }

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('x'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function idAktif(): string
    {
        return $this->palsu->tabel('/ip/hotspot/active')[0]['.id'];
    }

    public function test_data_menandai_halaman_dilihat_dan_melaporkan_keadaan_snapshot(): void
    {
        $this->siapkan();
        $this->actingAs($this->pengguna('operator'));
        $snap = app(RouterSnapshotService::class);

        $kosong = $this->getJson(route('hotspot.data', $this->router))->assertOk()->json();
        $this->assertNull($kosong['active']['data']);
        $this->assertFalse($kosong['poller_hidup']);
        $this->assertTrue($snap->watchedHotspot('uji'));

        $snap->beat();
        $snap->putHotspot('uji', 'active', [['.id' => '*1', 'user' => 'a']]);
        $segar = $this->getJson(route('hotspot.data', $this->router))->json();
        $this->assertTrue($segar['poller_hidup']);
        $this->assertCount(1, $segar['active']['data']);
        $this->assertFalse($segar['active']['basi']);

        Cache::put('mt.hotspot.uji', ['active' => ['ts' => microtime(true) - 90, 'data' => []]], 180);
        $this->assertTrue($this->getJson(route('hotspot.data', $this->router))->json('active.basi'));
    }

    public function test_admin_memutus_sesi_setelah_user_dan_mac_dicocokkan_ulang(): void
    {
        $this->siapkan();
        $this->actingAs($this->pengguna('admin'));
        $id = $this->idAktif();
        app(RouterSnapshotService::class)->putHotspot('uji', 'active', $this->palsu->tabel('/ip/hotspot/active'));

        $this->deleteJson(route('hotspot.kick', ['router' => $this->router, 'id' => $id]), ['user' => 'u1', 'mac' => 'aa:bb:cc:00:00:01'])
            ->assertOk();

        $this->assertSame([], $this->palsu->tabel('/ip/hotspot/active'));
        $this->assertSame([], app(RouterSnapshotService::class)->hotspot('uji')['active']['data']);
        $this->assertSame(1, ActivityLog::where('action', 'hotspot_kick')->count());
    }

    public function test_id_yang_kini_milik_user_lain_atau_sudah_hilang_ditolak_tanpa_menghapus(): void
    {
        $this->siapkan();
        $this->actingAs($this->pengguna('admin'));
        $id = $this->idAktif();

        $this->deleteJson(route('hotspot.kick', ['router' => $this->router, 'id' => $id]), ['user' => 'u9', 'mac' => 'AA:BB:CC:00:00:01'])
            ->assertStatus(409)->assertJsonPath('success', false);
        $this->deleteJson(route('hotspot.kick', ['router' => $this->router, 'id' => '*FFFF']), ['user' => 'u1', 'mac' => 'AA:BB:CC:00:00:01'])
            ->assertStatus(409);

        $this->assertCount(1, $this->palsu->tabel('/ip/hotspot/active'));
        $this->assertSame(0, ActivityLog::where('action', 'hotspot_kick')->count());
    }

    public function test_admin_menghapus_cookie_dan_operator_ditolak_server(): void
    {
        $this->siapkan();
        $cookie = $this->palsu->tabel('/ip/hotspot/cookie')[0]['.id'];

        $this->actingAs($this->pengguna('operator'));
        $this->deleteJson(route('hotspot.cookie', ['router' => $this->router, 'id' => $cookie]), ['user' => 'u1', 'mac' => 'AA:BB:CC:00:00:01'])->assertForbidden();
        $this->deleteJson(route('hotspot.kick', ['router' => $this->router, 'id' => $this->idAktif()]), ['user' => 'u1', 'mac' => 'AA:BB:CC:00:00:01'])->assertForbidden();
        $this->assertCount(1, $this->palsu->tabel('/ip/hotspot/cookie'));

        $this->flushSession();
        $this->actingAs($this->pengguna('admin'));
        $this->deleteJson(route('hotspot.cookie', ['router' => $this->router, 'id' => $cookie]), ['user' => 'u1', 'mac' => 'AA:BB:CC:00:00:01'])->assertOk();
        $this->assertSame([], $this->palsu->tabel('/ip/hotspot/cookie'));
    }

    public function test_halaman_tampil_untuk_operator_tanpa_aksi_tulis(): void
    {
        $this->siapkan();

        $this->actingAs($this->pengguna('operator'));
        $this->get(route('hotspot.index'))->assertOk()->assertSee('Hotspot aktif')->assertSee('\u0022kick\u0022:null', false);

        $this->flushSession();
        $this->actingAs($this->pengguna('admin'));
        $this->get(route('hotspot.index', ['router' => 'uji']))->assertOk()->assertDontSee('\u0022kick\u0022:null', false);
    }

    public function test_poller_membaca_hotspot_hanya_saat_halaman_dibuka_dan_di_key_terpisah(): void
    {
        $this->siapkan();
        app(RouterGateway::class)->aturKonteks(RouterGateway::POLLER);

        $poll = app(MikrotikPoll::class);
        $poll->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput()));
        foreach (['mt' => app(MikrotikService::class), 'lb' => app(LoadBalanceService::class), 'snap' => app(RouterSnapshotService::class)] as $k => $v) {
            (fn () => $this->{$k} = $v)->call($poll);
        }
        $putar = fn () => $this->putar('uji', false);

        $putar->call($poll);
        $this->assertArrayNotHasKey('/ip/hotspot/active', $this->palsu->hitung()['print'] ?? []);

        app(RouterSnapshotService::class)->watchHotspot('uji');
        (fn () => $this->state = [])->call($poll);
        $putar->call($poll);

        $this->assertSame(1, $this->palsu->hitung()['print']['/ip/hotspot/active']);
        $this->assertSame(1, $this->palsu->hitung()['print']['/ip/hotspot/cookie']);
        $this->assertCount(1, app(RouterSnapshotService::class)->hotspot('uji')['active']['data']);
        $this->assertArrayNotHasKey('active', Cache::get('mt.snap.uji') ?? []);
    }
}
