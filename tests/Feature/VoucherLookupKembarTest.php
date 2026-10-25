<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Services\VoucherLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherLookupKembarTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->palsu?->berhenti();
        parent::tearDown();
    }

    private function mulai(array $users): void
    {
        $this->palsu = RouterPalsu::mulai(
            array_map(fn ($u) => $u + ['profile' => '5-JAM', 'uptime' => '0s', 'comment' => 'up-001-uji'], $users),
            [],
            ['/ip/hotspot/active' => [], '/ip/hotspot/host' => [], '/ip/hotspot/cookie' => []],
        );

        Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);
    }

    private function cek(string $kode, string $pass = ''): array
    {
        return app(VoucherLookupService::class)->cek($kode, $pass, ['uji']);
    }

    public function test_kembar_memilih_baris_password_cocok(): void
    {
        $this->mulai([['name' => 'k1', 'password' => 'lain'], ['name' => 'k1', 'password' => 'p'], ['name' => 'K1', 'password' => 'p']]);

        $b = $this->cek('k1', 'p')['hasil'][0];

        $this->assertSame(['k1', 'p', false], [$b['user']['nama'], $b['user']['password'], $b['user']['huruf_beda']]);
        $this->assertSame(['k1/p', 'K1/p', 'k1/lain'], array_map(fn ($x) => $x['nama'] . '/' . $x['password'], $b['kembar']));
        $this->assertSame([true, true, false], array_column($b['kembar'], 'pass_cocok'));
        $this->assertSame([false, true, false], array_column($b['kembar'], 'huruf_beda'));
        $this->assertSame([true, false, false], array_column($b['kembar'], 'dipilih'));
    }

    public function test_tanpa_password_memilih_nama_persis(): void
    {
        $this->mulai([['name' => 'K1', 'password' => 'x'], ['name' => 'k1', 'password' => 'y']]);

        $b = $this->cek('k1')['hasil'][0];

        $this->assertSame('k1', $b['user']['nama']);
        $this->assertFalse($b['user']['huruf_beda']);
        $this->assertSame([null, null], array_column($b['kembar'], 'pass_cocok'));
    }

    public function test_hanya_ada_beda_huruf_ditandai(): void
    {
        $this->mulai([['name' => 'K1', 'password' => 'p']]);

        $b = $this->cek('k1', 'p')['hasil'][0];

        $this->assertSame('K1', $b['user']['nama']);
        $this->assertTrue($b['user']['huruf_beda']);
        $this->assertCount(1, $b['kembar']);
    }

    public function test_command_voucher_cek_tetap_jalan(): void
    {
        $this->mulai([['name' => 'k1', 'password' => 'lain'], ['name' => 'k1', 'password' => 'p']]);

        $this->artisan('voucher:cek', ['kode' => 'k1', '--pass' => 'p', '--router' => 'uji'])
            ->expectsOutputToContain('DATA DI ROUTER')
            ->assertExitCode(0);
    }

    public function test_html_hasil_menampilkan_daftar_kembar(): void
    {
        $this->mulai([['name' => 'k1', 'password' => 'lain'], ['name' => 'k1', 'password' => 'p'], ['name' => 'K1', 'password' => 'p']]);

        $html = view('voucher-check._hasil', ['r' => $this->cek('k1', 'p')])->render();

        $this->assertStringContainsString('Ada 3 user bernama sama di router ini', $html);
        $this->assertStringContainsString('huruf beda: login akan gagal', $html);
        $this->assertStringContainsString('dipakai di laporan', $html);
    }

    public function test_html_tanpa_kembar_tidak_menampilkan_daftar(): void
    {
        $this->mulai([['name' => 'k1', 'password' => 'p']]);

        $html = view('voucher-check._hasil', ['r' => $this->cek('k1', 'p')])->render();

        $this->assertStringNotContainsString('user bernama sama di router ini', $html);
        $this->assertStringNotContainsString('huruf beda', $html);
    }
}
