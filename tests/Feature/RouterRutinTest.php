<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Services\RouterSnapshotService;
use App\Services\TrafficService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class RouterRutinTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->palsu = RouterPalsu::mulai([], [], [
            '/interface' => [['name' => 'ether1', 'rx-byte' => '5000', 'tx-byte' => '7000', 'running' => 'true']],
            '/ip/hotspot/active' => [],
        ]);
        Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia', 'wan_interface' => 'ether1',
        ]);
    }

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        parent::tearDown();
    }

    private function snapshot(float $umur): void
    {
        $snap = app(RouterSnapshotService::class);
        $snap->beat();
        $snap->put('uji', [
            'online'   => true,
            'error'    => null,
            'ifaces'   => [['ts' => microtime(true) - $umur, 'c' => ['ether1' => ['rx' => 111, 'tx' => 222, 'running' => true]]]],
            'resource' => ['ts' => microtime(true) - $umur - 20, 'data' => ['uptime' => '1h']],
        ]);
    }

    public function test_sampel_trafik_memakai_snapshot_poller_tanpa_login_ke_router(): void
    {
        $this->snapshot(10);

        $rows = app(TrafficService::class)->sample('uji');

        $this->assertSame(0, $this->palsu->hitung()['koneksi']);
        $this->assertSame([['ether1', 111, 222, 3620]], array_map(fn ($r) => [$r['interface'], $r['rx_bytes'], $r['tx_bytes'], $r['uptime']], $rows));
    }

    public function test_snapshot_basi_membuat_sampel_trafik_membaca_router_langsung(): void
    {
        $this->snapshot(300);

        $rows = app(TrafficService::class)->sample('uji');

        $this->assertGreaterThan(0, $this->palsu->hitung()['koneksi']);
        $this->assertSame(5000, $rows[0]['rx_bytes']);
    }

    public function test_tugas_rutin_berbagi_satu_sesi_api_per_router(): void
    {
        $this->snapshot(10);

        $this->artisan('router:rutin', ['--sinkron' => true])->assertSuccessful();

        $this->assertSame(1, $this->palsu->hitung()['koneksi']);
        $this->assertSame(1, DB::table(TrafficService::TABLE)->where('router_slug', 'uji')->count());
    }
}
