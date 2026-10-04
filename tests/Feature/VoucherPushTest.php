<?php

namespace Tests\Feature;

use App\Exceptions\RouterSibuk;
use App\Jobs\SyncVoucherBatch;
use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherPushService;
use App\Services\VoucherService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherPushTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    private Router $router;

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        parent::tearDown();
    }

    private function siapkan(int $jumlah, array $usersRouter = [], array $skenario = []): VoucherBatch
    {
        $this->palsu  = RouterPalsu::mulai($usersRouter, $skenario);
        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);

        $batch = VoucherBatch::create([
            'router_id' => $this->router->id, 'router_name' => 'UJI', 'code' => 'up-001-09.26.26-uji',
            'profile' => '5-JAM', 'quantity' => $jumlah, 'status' => 'pending',
        ]);

        for ($i = 1; $i <= $jumlah; $i++) {
            Voucher::create([
                'batch_id' => $batch->id, 'router_id' => $this->router->id, 'username' => "kode{$i}",
                'password' => "p{$i}", 'profile' => '5-JAM', 'comment' => $batch->code,
                'status' => 'ready', 'sync_status' => 'pending',
            ]);
        }

        return $batch;
    }

    private function push(VoucherBatch $batch): array
    {
        return app(VoucherPushService::class)->push($batch->fresh());
    }

    private function namaDiRouter(): array
    {
        return array_column($this->palsu->users(), 'name');
    }

    public function test_push_normal_semua_masuk_sekali(): void
    {
        $batch = $this->siapkan(60);

        $this->assertSame(['ok' => 60, 'failed' => 0], $this->push($batch));
        $this->assertCount(60, $this->namaDiRouter());
        $this->assertSame('success', $batch->fresh()->status);
        $this->assertSame(60, $batch->fresh()->synced_count);
    }

    public function test_putus_di_tengah_lalu_retry_tidak_menggandakan_walau_router_terima_nama_kembar(): void
    {
        $batch = $this->siapkan(60, [], ['putus_setelah_add' => 30, 'kembar_boleh' => true]);

        try {
            $this->push($batch);
            $this->fail('push pertama seharusnya putus');
        } catch (Exception $e) {
            $this->assertStringContainsString('terputus', $e->getMessage());
        }

        $status = Voucher::orderBy('id')->pluck('sync_status')->countBy()->all();
        $this->assertSame(['success' => 25, VoucherPushService::KIRIM => 25, 'pending' => 10], $status);
        $this->assertCount(30, $this->namaDiRouter());

        $this->assertSame(['ok' => 35, 'failed' => 0], $this->push($batch));

        $nama = $this->namaDiRouter();
        $this->assertCount(60, $nama);
        $this->assertCount(60, array_unique($nama));
        $this->assertSame(60, $this->palsu->hitung()['add']);
        $this->assertSame('success', $batch->fresh()->status);
    }

    public function test_nama_sudah_dipakai_user_lain_ditandai_bentrok_dan_tidak_ditambah(): void
    {
        $batch = $this->siapkan(3, [['name' => 'kode2', 'password' => 'milik-orang-lain', 'comment' => 'up-lama']]);

        $this->assertSame(['ok' => 2, 'failed' => 1], $this->push($batch));

        $bentrok = Voucher::where('username', 'kode2')->first();
        $this->assertSame('failed', $bentrok->sync_status);
        $this->assertStringStartsWith(VoucherService::BENTROK_PREFIX, $bentrok->sync_error);

        $this->palsu->skenario(['kembar_boleh' => true]);
        $this->assertSame(['ok' => 0, 'failed' => 1], $this->push($batch));
        $this->assertSame(1, count(array_keys($this->namaDiRouter(), 'kode2')));
    }

    public function test_router_sedang_dipakai_proses_lain_push_ditolak_tanpa_mengubah_apa_pun(): void
    {
        $batch = $this->siapkan(5);
        $lock  = Cache::lock('router.tulis.uji', 60);
        $this->assertTrue($lock->get());

        try {
            $this->push($batch);
            $this->fail('seharusnya sibuk');
        } catch (RouterSibuk) {
        }

        (new SyncVoucherBatch($batch->id))->handle(app(VoucherPushService::class));

        $this->assertSame(0, $this->palsu->hitung()['add']);
        $this->assertSame(5, Voucher::where('sync_status', 'pending')->count());

        $lock->release();
        $this->assertSame(['ok' => 5, 'failed' => 0], $this->push($batch));
    }

    public function test_hapus_user_dari_web_menunggu_proses_lain_selesai(): void
    {
        $batch = $this->siapkan(2);
        $this->push($batch);
        $this->assertTrue(Cache::lock('router.tulis.uji', 1)->get());

        $mulai = microtime(true);
        $hasil = app(\App\Services\VoucherRouterService::class)->removeUsers($this->router, ['kode1']);

        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $mulai);
        $this->assertSame(1, $hasil['removed']);
        $this->assertSame(['kode2'], $this->namaDiRouter());
    }
}
