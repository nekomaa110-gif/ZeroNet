<?php

namespace Tests\Feature;

use App\Exceptions\RouterSibuk;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\RouterGateway;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class RouterGatewayTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $router = null;

    protected function tearDown(): void
    {
        $this->router?->berhenti();
        parent::tearDown();
    }

    private function gateway(string $konteks = RouterGateway::WEB): RouterGateway
    {
        $g = app(RouterGateway::class);
        $g->aturKonteks($konteks);

        return $g;
    }

    private function identitas(RouterGateway $g, array $cfg): string
    {
        return $g->jalankan('uji', $cfg, fn ($c) => $c->query('/system/identity/print')[0]['name']);
    }

    public function test_koneksi_dipakai_ulang_dalam_satu_proses(): void
    {
        $this->router = RouterPalsu::mulai();
        $g = $this->gateway();

        $this->assertSame('UJI', $this->identitas($g, $this->router->config()));
        $this->assertSame('UJI', $this->identitas($g, $this->router->config()));
        $this->assertSame(1, $this->router->hitung()['koneksi']);
    }

    public function test_koneksi_putus_disambung_ulang_otomatis(): void
    {
        $this->router = RouterPalsu::mulai([], ['putus_setelah_add' => 1]);
        $g   = $this->gateway();
        $cfg = $this->router->config();

        try {
            $g->klien('uji', $cfg)->query('/ip/hotspot/user/add', ['=name' => 'a1', '=password' => 'x']);
            $this->fail('seharusnya putus');
        } catch (Exception $e) {
            $this->assertStringContainsString('terputus', $e->getMessage());
        }

        $this->assertSame('UJI', $g->klien('uji', $cfg)->query('/system/identity/print')[0]['name']);
        $this->assertSame(2, $this->router->hitung()['koneksi']);
    }

    public function test_router_mati_ditandai_down_dan_web_gagal_cepat(): void
    {
        $this->router = RouterPalsu::mulai();
        $cfg = $this->router->config();
        $this->router->berhenti();
        $this->router = null;

        $g = $this->gateway();

        try {
            $g->klien('uji', $cfg);
            $this->fail('seharusnya gagal');
        } catch (Exception) {
        }

        $this->assertTrue(Cache::has('router.down.uji'));

        $mulai = microtime(true);
        try {
            $g->klien('uji', $cfg);
            $this->fail('seharusnya gagal cepat');
        } catch (Exception $e) {
            $this->assertStringContainsString('dicoba lagi', $e->getMessage());
        }
        $this->assertLessThan(0.2, microtime(true) - $mulai);
    }

    public function test_poller_tidak_terhalang_penanda_down_dan_menghapusnya(): void
    {
        $this->router = RouterPalsu::mulai();
        Cache::put('router.down.uji', true, 15);

        $this->assertSame('UJI', $this->identitas($this->gateway(RouterGateway::POLLER), $this->router->config()));
        $this->assertFalse(Cache::has('router.down.uji'));
    }

    public function test_router_diam_berakhir_timeout_lalu_disambung_ulang(): void
    {
        $this->router = RouterPalsu::mulai([], ['diam' => true]);
        $g   = $this->gateway();
        $cfg = $this->router->config();

        $mulai = microtime(true);
        try {
            $g->klien('uji', $cfg, 1)->query('/system/identity/print');
            $this->fail('seharusnya timeout');
        } catch (Exception $e) {
            $this->assertStringContainsString('timeout', $e->getMessage());
        }
        $this->assertLessThan(3, microtime(true) - $mulai);

        $this->router->skenario(['diam' => false]);
        $this->assertSame('UJI', $this->identitas($g, $cfg));
        $this->assertSame(2, $this->router->hitung()['koneksi']);
    }

    public function test_koneksi_menganggur_disambung_ulang_kecuali_poller(): void
    {
        config(['services.mikrotik.idle_ulang' => 0]);
        $this->router = RouterPalsu::mulai();
        $cfg = $this->router->config();

        $web = $this->gateway();
        $this->identitas($web, $cfg);
        $this->identitas($web, $cfg);
        $this->assertSame(2, $this->router->hitung()['koneksi']);

        $web->lupakanSemua();
        $poller = $this->gateway(RouterGateway::POLLER);
        $this->identitas($poller, $cfg);
        $this->identitas($poller, $cfg);
        $this->assertSame(3, $this->router->hitung()['koneksi']);
    }

    public function test_kunci_tulis_menolak_proses_kedua_lalu_lepas(): void
    {
        $g = $this->gateway();

        $hasil = $g->kunciTulis('uji', function () use ($g) {
            try {
                $g->kunciTulis('uji', fn () => 'tidak boleh');
                $this->fail('seharusnya sibuk');
            } catch (RouterSibuk) {
            }

            return 'pertama';
        });

        $this->assertSame('pertama', $hasil);
        $this->assertSame('kedua', $g->kunciTulis('uji', fn () => 'kedua'));
    }

    public function test_kunci_milik_pekerja_mati_lepas_sendiri_setelah_ttl(): void
    {
        $this->assertTrue(Cache::lock('router.tulis.uji', 60)->get());

        $g = $this->gateway();
        $this->expectException(RouterSibuk::class);

        try {
            $g->kunciTulis('uji', fn () => null);
        } finally {
            $this->travel(61)->seconds();
            $this->assertSame('pulih', $g->kunciTulis('uji', fn () => 'pulih'));
        }
    }

    public function test_mikrotik_service_lewat_gateway(): void
    {
        $this->router = RouterPalsu::mulai();
        Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->router->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);
        MikrotikService::forgetRouterCache();

        $mt = app(MikrotikService::class);
        $this->assertTrue($mt->isOnline('uji'));
        $this->assertSame('UJI', $mt->stats('uji')['identity']);
        $this->assertSame(1, $this->router->hitung()['koneksi']);
    }
}
