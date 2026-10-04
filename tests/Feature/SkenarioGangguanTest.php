<?php

namespace Tests\Feature;

use App\Jobs\SyncVoucherBatch;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherImportService;
use App\Services\VoucherPushService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class SkenarioGangguanTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    protected function tearDown(): void
    {
        $this->palsu?->berhenti();
        parent::tearDown();
    }

    private function router(array $users = [], array $skenario = [], array $tabel = []): Router
    {
        $this->palsu = RouterPalsu::mulai($users, $skenario, $tabel);

        return Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);
    }

    private function batch(Router $router, array $voucher, string $status = 'pending'): VoucherBatch
    {
        $batch = VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => $router->name, 'code' => 'up-009-09.27.26-uji',
            'profile' => '5-JAM', 'quantity' => count($voucher), 'status' => $status,
        ]);

        foreach ($voucher as [$user, $profil]) {
            Voucher::create([
                'batch_id' => $batch->id, 'router_id' => $router->id, 'username' => $user, 'password' => "p-{$user}",
                'profile' => $profil, 'comment' => $batch->code, 'status' => 'ready', 'sync_status' => 'pending',
            ]);
        }

        return $batch;
    }

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('x'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_worker_mati_batch_syncing_basi_boleh_dihapus_dan_dikirim_ulang_sinkron(): void
    {
        $router = $this->router();
        $batch  = $this->batch($router, [['w1', '5-JAM']], 'syncing');
        $this->actingAs($this->pengguna('admin'));

        $this->delete(route('vouchers.destroy', $batch))->assertSessionHas('error');
        $this->assertNotNull($batch->fresh());

        VoucherBatch::whereKey($batch->id)->update(['updated_at' => now()->subMinutes(20)]);

        Queue::fake();
        $this->artisan('voucher:sinkron')->assertSuccessful();
        Queue::assertPushed(SyncVoucherBatch::class, fn ($job) => $job->batchId === $batch->id);

        $this->delete(route('vouchers.destroy', $batch))->assertSessionHas('success');
        $this->assertNull($batch->fresh());
    }

    public function test_profil_yang_hilang_di_router_hanya_menggagalkan_voucher_itu(): void
    {
        $router = $this->router([], [], ['/ip/hotspot/user/profile' => [['name' => '5-JAM', 'on-login' => '']]]);
        $batch  = $this->batch($router, [['q1', '5-JAM'], ['q2', 'PROFIL-DIHAPUS'], ['q3', '5-JAM']]);

        $this->assertSame(['ok' => 2, 'failed' => 1], app(VoucherPushService::class)->push($batch));

        $gagal = Voucher::where('username', 'q2')->first();
        $this->assertSame('failed', $gagal->sync_status);
        $this->assertStringContainsString('profile', $gagal->sync_error);
        $this->assertSame(['q1', 'q3'], array_column($this->palsu->users(), 'name'));
        $this->assertSame('partial', $batch->fresh()->status);
    }

    public function test_router_diam_saat_impor_tidak_menyisakan_voucher_setengah_masuk_dan_bisa_diulang(): void
    {
        config(['services.mikrotik.timeout.antrean' => 2]);
        $router = $this->router([['name' => 'm1', 'password' => 'x', 'profile' => '5-JAM', 'comment' => 'up-001-x']], ['diam' => true]);

        try {
            app(VoucherImportService::class)->impor($router);
            $this->fail('seharusnya timeout');
        } catch (Exception $e) {
            $this->assertStringContainsString('timeout', $e->getMessage());
        }
        $this->assertSame(0, Voucher::count());

        $this->palsu->skenario(['diam' => false]);
        $this->assertSame(1, app(VoucherImportService::class)->impor($router->fresh())['masuk']);
    }

    public function test_rute_baru_menolak_tamu_dan_operator_di_server(): void
    {
        $router = Router::create(['slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);

        $adminSaja = [
            ['getJson', route('vouchers.import.preview', $router)],
            ['postJson', route('vouchers.import', $router)],
            ['getJson', route('penjualan.index')],
            ['deleteJson', route('hotspot.kick', ['router' => $router, 'id' => '*1'])],
            ['deleteJson', route('hotspot.cookie', ['router' => $router, 'id' => '*1'])],
            ['postJson', route('vouchers.store')],
            ['postJson', route('vouchers.reconcile')],
        ];
        $semuaPeran = [
            ['getJson', route('hotspot.index')],
            ['getJson', route('hotspot.data', $router)],
        ];

        foreach (array_merge($adminSaja, $semuaPeran) as [$metode, $url]) {
            $this->assertContains($this->{$metode}($url)->status(), [401, 302], "tamu lolos: {$url}");
        }

        $this->actingAs($this->pengguna('operator'));
        foreach ($adminSaja as [$metode, $url]) {
            $this->{$metode}($url)->assertForbidden();
        }
        $this->getJson(route('hotspot.data', $router))->assertOk();
    }
}
