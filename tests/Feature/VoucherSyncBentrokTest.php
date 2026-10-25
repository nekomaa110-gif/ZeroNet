<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherPushService;
use App\Services\VoucherRouterService;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VoucherSyncBentrokTest extends TestCase
{
    use RefreshDatabase;

    private function batchDenganVoucher(array $voucher): VoucherBatch
    {
        $router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => 8728,
            'username' => 'api', 'password' => 'rahasia',
        ]);

        $batch = VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => 'UJI', 'code' => 'vc-001-09.26.26-uji',
            'profile' => '5-JAM', 'quantity' => count($voucher), 'status' => 'pending',
        ]);

        foreach ($voucher as [$user, $pass]) {
            Voucher::create([
                'batch_id' => $batch->id, 'router_id' => $router->id, 'username' => $user,
                'password' => $pass, 'profile' => '5-JAM', 'comment' => $batch->code,
                'status' => 'ready', 'sync_status' => 'pending',
            ]);
        }

        return $batch;
    }

    private function routerPalsu(array $identitas): void
    {
        $mock = Mockery::mock(VoucherRouterService::class);
        $mock->shouldReceive('addUsers')->andReturnUsing(fn ($router, array $rows) => array_map(
            fn ($r) => ['name' => $r['name'], 'ok' => false, 'error' => 'failure: already have user with this name for this server'],
            $rows
        ));
        $mock->shouldReceive('userIdentities')->andReturn($identitas);

        $this->app->instance(VoucherRouterService::class, $mock);
    }

    public function test_voucher_yang_sudah_dipakai_tetap_dikenali_walau_comment_berubah_jadi_stempel(): void
    {
        $batch = $this->batchDenganVoucher([['abc1', 'p111'], ['abc2', 'p222']]);

        $this->routerPalsu([
            'abc1' => [['comment' => 'sep/26/2026 20:00:00', 'password' => 'p111']],
            'abc2' => [['comment' => 'vc-001-09.26.26-uji', 'password' => 'bukan-milik-kita']],
        ]);

        $hasil = app(VoucherPushService::class)->push($batch);

        $this->assertSame(['ok' => 1, 'failed' => 1], $hasil);
        $this->assertSame('success', Voucher::where('username', 'abc1')->value('sync_status'));

        $bentrok = Voucher::where('username', 'abc2')->first();
        $this->assertSame('failed', $bentrok->sync_status);
        $this->assertStringStartsWith(VoucherService::BENTROK_PREFIX, $bentrok->sync_error);
    }

    public function test_comment_jadi_cadangan_kalau_password_tidak_terbaca(): void
    {
        $batch = $this->batchDenganVoucher([['abc1', 'p111'], ['abc2', 'p222']]);

        $this->routerPalsu([
            'abc1' => [['comment' => 'vc-001-09.26.26-uji', 'password' => null]],
            'abc2' => [['comment' => 'sep/26/2026 20:00:00', 'password' => null]],
        ]);

        $hasil = app(VoucherPushService::class)->push($batch);

        $this->assertSame(['ok' => 1, 'failed' => 1], $hasil);
        $this->assertSame('success', Voucher::where('username', 'abc1')->value('sync_status'));
    }

    public function test_kembaran_di_router_dikenali_bila_salah_satu_cocok(): void
    {
        $batch = $this->batchDenganVoucher([['abc1', 'p111']]);
        $this->routerPalsu(['abc1' => [['comment' => 'x', 'password' => 'lain'], ['comment' => 'y', 'password' => 'p111']]]);

        $this->assertSame(['ok' => 1, 'failed' => 0], app(VoucherPushService::class)->push($batch));
        $this->assertSame('success', Voucher::where('username', 'abc1')->value('sync_status'));
    }

    public function test_kartu_berhuruf_besar_dan_kartu_angka_dikenali_sudah_ada(): void
    {
        $batch = $this->batchDenganVoucher([['0X49079', '4827'], ['48213', '2345']]);
        $this->routerPalsu([
            '0X49079' => [['comment' => 'x', 'password' => '4827']],
            '48213'   => [['comment' => 'y', 'password' => '2345']],
        ]);

        $this->assertSame(['ok' => 2, 'failed' => 0], app(VoucherPushService::class)->push($batch));
    }

    public function test_kembaran_tanpa_password_cocok_tetap_bentrok(): void
    {
        $batch = $this->batchDenganVoucher([['abc2', 'p222']]);
        $this->routerPalsu(['abc2' => [['comment' => 'x', 'password' => 'a'], ['comment' => 'y', 'password' => 'b']]]);

        $this->assertSame(['ok' => 0, 'failed' => 1], app(VoucherPushService::class)->push($batch));
        $this->assertStringStartsWith(VoucherService::BENTROK_PREFIX, Voucher::where('username', 'abc2')->value('sync_error'));
    }
}
