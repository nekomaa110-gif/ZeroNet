<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VoucherSale;
use App\Services\VoucherImportService;
use App\Services\VoucherSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherImportTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    private Router $router;

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        parent::tearDown();
    }

    private function siapkan(): void
    {
        $stempelLalu  = strtolower(now()->subDays(6)->format('M/d/Y')) . ' 10:00:00';
        $stempelNanti = now()->addDays(20)->format('Y-m-d') . ' 08:00:00';
        $jual         = strtolower(now()->subDays(7)->format('M/d/Y'));

        $this->palsu = RouterPalsu::mulai([
            ['name' => 'u1', 'password' => 'p1', 'profile' => '5-JAM', 'comment' => 'up-001-09.26.26-stok1', 'uptime' => '0s'],
            ['name' => 'u2', 'password' => 'p2', 'profile' => '5-JAM', 'comment' => $stempelLalu, 'uptime' => '3h', 'limit-uptime' => '1s'],
            ['name' => 'u3', 'password' => 'p3', 'profile' => '5-JAM', 'comment' => $stempelNanti, 'uptime' => '1h'],
            ['name' => 'admin', 'password' => 'x', 'profile' => 'admin', 'comment' => ''],
            ['name' => 'dup', 'password' => 'a', 'profile' => '5-JAM', 'comment' => 'up-002-x'],
            ['name' => 'DUP', 'password' => 'b', 'profile' => '5-JAM', 'comment' => 'up-002-x'],
            ['name' => 'lama', 'password' => 'pl', 'profile' => '5-JAM', 'comment' => 'up-003-panel'],
        ], [], [
            '/system/script' => [
                ['name' => "{$jual}-|-10:00:00-|-u2-|-5000-|-10.10.10.9-|-AA:BB:CC:DD:EE:01-|-1d-|-5-JAM-|-up-001-09.26.26-stok1", 'owner' => 'x', 'comment' => 'mikhmon'],
            ],
            '/ip/hotspot/user/profile' => [['name' => '5-JAM', 'on-login' => ':put (",ntfc,5000,1d,0,,Disable,")']],
        ]);

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);

        $batch = VoucherBatch::create([
            'router_id' => $this->router->id, 'router_name' => 'UJI', 'code' => 'up-003-panel',
            'profile' => '5-JAM', 'quantity' => 1, 'status' => 'success',
        ]);
        Voucher::create([
            'batch_id' => $batch->id, 'router_id' => $this->router->id, 'username' => 'lama', 'password' => 'pl',
            'profile' => '5-JAM', 'comment' => 'up-003-panel', 'status' => 'ready', 'sync_status' => 'success',
        ]);
    }

    public function test_pratinjau_mengelompokkan_semua_user_tanpa_menulis_apa_pun(): void
    {
        $this->siapkan();

        $r = app(VoucherImportService::class)->rencana($this->router)['ringkasan'];

        $this->assertSame([
            'user_router' => 7, 'belum_dipakai' => 1, 'terpakai' => 2, 'terpakai_tanpa_batch' => 1,
            'sudah_ada' => 1, 'bukan_voucher' => 1, 'nama_kembar' => 2,
        ], $r);
        $this->assertSame(1, Voucher::count());
    }

    public function test_impor_menyimpan_batch_asal_waktu_pakai_dan_status_dari_router(): void
    {
        $this->siapkan();
        app(VoucherSalesService::class)->tarik($this->router);

        $hasil = app(VoucherImportService::class)->impor($this->router);

        $this->assertSame(3, $hasil['masuk']);
        $this->assertSame(1, $hasil['penjualan_tertaut']);

        $u1 = Voucher::where('username', 'u1')->first();
        $u2 = Voucher::where('username', 'u2')->first();
        $u3 = Voucher::where('username', 'u3')->first();

        $this->assertSame('up-001-09.26.26-stok1@uji', $u1->batch->code);
        $this->assertSame($u1->batch_id, $u2->batch_id);
        $this->assertSame('tanpa-batch@uji', $u3->batch->code);
        $this->assertSame('mikhmon_import', $u1->batch->source);

        $this->assertSame(['ready', 'expired', 'active'], [$u1->status, $u2->status_efektif, $u3->status_efektif]);
        $this->assertSame(now()->subDays(7)->format('Y-m-d') . ' 10:00:00', $u2->activated_at->format('Y-m-d H:i:s'));
        $this->assertSame(5000, $u1->price);
        $this->assertNull($u2->price);

        $this->assertSame($u2->id, VoucherSale::where('username', 'u2')->value('voucher_id'));
    }

    public function test_impor_diulang_tidak_menggandakan_dan_hanya_menambah_yang_baru(): void
    {
        $this->siapkan();
        $import = app(VoucherImportService::class);

        $import->impor($this->router);
        $this->assertSame(0, $import->impor($this->router)['masuk']);
        $this->assertSame(4, Voucher::count());
        $this->assertSame(3, VoucherBatch::count());

        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'u9', 'password' => 'p9', 'profile' => '5-JAM', 'comment' => 'up-001-09.26.26-stok1']]);

        $this->assertSame(1, $import->impor($this->router)['masuk']);
        $this->assertSame(3, VoucherBatch::where('code', 'up-001-09.26.26-stok1@uji')->first()->quantity);
    }

    public function test_impor_bersamaan_untuk_router_yang_sama_ditolak(): void
    {
        $this->siapkan();
        $this->assertTrue(Cache::lock('voucher.import.uji', 60)->get());

        $this->expectException(RuntimeException::class);
        app(VoucherImportService::class)->impor($this->router);
    }
}
