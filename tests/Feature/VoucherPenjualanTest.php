<?php

namespace Tests\Feature;

use App\Exceptions\RouterSibuk;
use App\Models\Router;
use App\Models\VoucherSale;
use App\Services\VoucherSalesReport;
use App\Services\VoucherSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherPenjualanTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    private Router $router;

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        parent::tearDown();
    }

    private function siapkan(array $record, array $profil = [], string $gmt = '+07:00'): void
    {
        $this->palsu = RouterPalsu::mulai([], [], [
            '/system/script'           => array_map(fn ($n) => ['name' => $n, 'owner' => 'x', 'comment' => 'mikhmon'], $record),
            '/ip/hotspot/user/profile' => $profil ?: [['name' => '5-JAM', 'on-login' => ':put (",ntfc,5000,1d,6000,,Disable,")']],
        ], ['gmt-offset' => $gmt]);

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);
    }

    private static function record(Carbon $waktu, string $user, int $harga = 5000, bool $iso = false): string
    {
        $tanggal = $iso ? $waktu->format('Y-m-d') : strtolower($waktu->format('M/d/Y'));

        return "{$tanggal}-|-{$waktu->format('H:i:s')}-|-{$user}-|-{$harga}-|-10.10.10.254-|-AA:BB:CC:DD:EE:FF-|-1d-|-5-JAM-|-up-001-09.26.26-stok1";
    }

    private function tarik(bool $penuh = false): array
    {
        return app(VoucherSalesService::class)->tarik($this->router->fresh(), $penuh);
    }

    public function test_tarik_berulang_tidak_menggandakan_dan_gate_jumlah_melewati_router_yang_tidak_berubah(): void
    {
        $this->siapkan([self::record(now()->subMinutes(5), 'a1'), self::record(now()->subMinutes(4), 'a2'), self::record(now()->subMinutes(3), 'a3')]);

        $this->assertSame(3, $this->tarik()['baru']);
        $this->router->forceFill(['last_sales_pull_at' => null])->saveQuietly();
        $this->assertTrue($this->tarik()['dilewati']);
        $this->assertNotNull($this->router->fresh()->last_sales_pull_at);
        $this->assertSame(0, $this->tarik(true)['baru']);
        $this->assertSame(3, VoucherSale::count());
        $this->assertSame(2, $this->palsu->hitung()['print']['/system/script']);
    }

    public function test_record_terlambat_tetap_masuk_karena_tidak_ada_watermark_waktu(): void
    {
        $this->siapkan([self::record(now()->subMinutes(2), 'b1')]);
        $this->tarik();

        $this->palsu->tambah('/system/script', [['name' => self::record(now()->subDays(3)->setTime(9, 15), 'b2'), 'owner' => 'x', 'comment' => 'mikhmon']]);

        $this->assertSame(1, $this->tarik()['baru']);
        $this->assertSame(now()->subDays(3)->format('Y-m-d') . ' 09:15:00', VoucherSale::where('username', 'b2')->first()->sold_at->format('Y-m-d H:i:s'));
    }

    public function test_jam_router_ngaco_disimpan_sebagai_ragu_dan_tidak_masuk_laporan(): void
    {
        $this->siapkan([
            'jan/02/1970-|-00:00:07-|-c1-|-5000-|-ip-|-mac-|-1d-|-5-JAM-|-up-x',
            strtolower(now()->addMonths(2)->format('M/d/Y')) . '-|-10:00:00-|-c2-|-5000-|-ip-|-mac-|-1d-|-5-JAM-|-up-x',
            self::record(now()->subMinutes(1), 'c3'),
        ]);

        $this->assertSame(3, $this->tarik()['baru']);
        $this->assertSame(2, VoucherSale::where('waktu_ragu', true)->count());

        $r = app(VoucherSalesReport::class)->ringkasan();
        $this->assertSame(1, $r['hari_ini']['total']['transaksi']);
        $this->assertSame(2, $r['waktu_ragu']);
    }

    public function test_format_tanggal_ros6_ros7_dan_offset_detik_terbaca_sama(): void
    {
        $waktu = now()->subHour()->setTime((int) now()->subHour()->format('H'), 30, 0);
        $this->siapkan([self::record($waktu, 'd1'), self::record($waktu, 'd2', iso: true)], gmt: '25200');

        $this->tarik();

        $this->assertSame(
            [$waktu->format('Y-m-d H:i:s'), $waktu->format('Y-m-d H:i:s')],
            VoucherSale::orderBy('username')->get()->map(fn ($s) => $s->sold_at->format('Y-m-d H:i:s'))->all(),
        );
    }

    public function test_harga_jual_hanya_diisi_saat_ditarik_dekat_waktu_jual_dan_harga_profil_sama(): void
    {
        $this->siapkan([
            self::record(now()->subMinutes(10), 'e1'),
            self::record(now()->subDays(3), 'e2'),
            self::record(now()->subMinutes(10), 'e3', 4000),
        ]);

        $this->tarik();

        $this->assertSame(6000, VoucherSale::where('username', 'e1')->value('sprice'));
        $this->assertNull(VoucherSale::where('username', 'e2')->value('sprice'));
        $this->assertNull(VoucherSale::where('username', 'e3')->value('sprice'));

        $r = app(VoucherSalesReport::class)->ringkasan()['hari_ini']['total'];
        $this->assertSame(['transaksi' => 2, 'omzet' => 9000, 'transaksi_harga_jual' => 1, 'harga_jual' => 6000, 'margin' => 1000], $r);
    }

    public function test_sprice_nol_dianggap_belum_diisi_dan_margin_negatif_tidak_error(): void
    {
        $this->siapkan([self::record(now()->subMinutes(5), 'f1')], [['name' => '5-JAM', 'on-login' => ':put (",ntfc,5000,1d,0,,Disable,")']]);
        $this->tarik();
        $this->assertNull(VoucherSale::first()->sprice);

        VoucherSale::query()->update(['sprice' => 4000, 'sprice_sumber' => 'profil_saat_tarik']);
        Cache::flush();

        $this->assertSame(-1000, app(VoucherSalesReport::class)->ringkasan()['hari_ini']['total']['margin']);
    }

    public function test_arsip_hanya_menghapus_record_tersalin_yang_lebih_tua_dari_bulan_berjalan_plus_tiga(): void
    {
        $batas = now()->startOfMonth()->subMonths(3);
        $this->siapkan([
            self::record($batas->copy()->subMonths(2)->addDays(3), 'g1'),
            self::record($batas->copy()->subDay(), 'g2'),
            self::record($batas->copy()->addDay(), 'g3'),
            self::record(now()->subMinutes(5), 'g4'),
            'jan/02/1970-|-00:00:07-|-g5-|-5000-|-ip-|-mac-|-1d-|-5-JAM-|-up-x',
            'bukan-record-mikhmon',
        ]);

        $dry = app(VoucherSalesService::class)->arsip($this->router->fresh());
        $this->assertSame(2, $dry['akan_dihapus']);
        $this->assertSame(0, $dry['dihapus']);
        $this->assertCount(6, $this->palsu->tabel('/system/script'));

        $jalan = app(VoucherSalesService::class)->arsip($this->router->fresh(), true);
        $this->assertSame(2, $jalan['dihapus']);
        $this->assertSame(1, $jalan['tanpa_salinan']);
        $this->assertSame(1, $jalan['waktu_ragu']);

        $sisa = array_map(fn ($s) => explode('-|-', $s['name'])[2] ?? $s['name'], $this->palsu->tabel('/system/script'));
        sort($sisa);
        $this->assertSame(['bukan-record-mikhmon', 'g3', 'g4', 'g5'], $sisa);

        $this->assertSame(5, VoucherSale::count());
        $this->assertSame(2, VoucherSale::whereNotNull('dihapus_dari_router_at')->count());
    }

    public function test_arsip_ditolak_saat_router_sedang_ditulis_proses_lain(): void
    {
        $this->siapkan([self::record(now()->subYear(), 'h1')]);
        $this->assertTrue(Cache::lock('router.tulis.uji', 60)->get());

        $this->expectException(RouterSibuk::class);
        app(VoucherSalesService::class)->arsip($this->router->fresh(), true);
    }
}
