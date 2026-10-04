<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherHapusMassalTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    private VoucherBatch $batch;

    private VoucherBatch $lain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();

        $kartu = [];
        foreach (range(1, 55) as $i) {
            $kartu[sprintf('x%02d', $i)] = ['status' => 'expired'];
        }
        foreach (range(1, 5) as $i) {
            $kartu[sprintf('r%02d', $i)] = ['status' => 'ready'];
        }
        $kartu['a01']     = ['status' => 'active', 'expired_at' => now()->addHours(3)];
        $kartu['kembar1'] = ['status' => 'expired', 'sync_error' => VoucherService::BENTROK_PREFIX . ': nama sudah dipakai user lain'];

        $this->palsu = RouterPalsu::mulai(array_map(
            fn ($nama) => ['name' => $nama, 'password' => 'p', 'profile' => '4-JAM'],
            [...array_keys($kartu), 'b01'],
        ));

        $router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->batch = VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => 'UJI', 'code' => 'up-176-uji',
            'profile' => '4-JAM', 'quantity' => count($kartu), 'status' => 'success',
        ]);
        foreach ($kartu as $nama => $isi) {
            Voucher::create($isi + [
                'batch_id' => $this->batch->id, 'router_id' => $router->id, 'username' => $nama, 'password' => 'p',
                'profile' => '4-JAM', 'sync_status' => 'success',
            ]);
        }

        $this->lain = VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => 'UJI', 'code' => 'up-999-lain',
            'profile' => '4-JAM', 'quantity' => 1, 'status' => 'success',
        ]);
        Voucher::create([
            'batch_id' => $this->lain->id, 'router_id' => $router->id, 'username' => 'b01', 'password' => 'p',
            'profile' => '4-JAM', 'status' => 'ready', 'sync_status' => 'success',
        ]);
    }

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        parent::tearDown();
    }

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('sandi-admin'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function id(string ...$nama): array
    {
        return Voucher::whereIn('username', $nama)->pluck('id')->all();
    }

    private function diRouter(): array
    {
        return array_column($this->palsu->users(), 'name');
    }

    public function test_hapus_pilihan_manual_dari_panel_dan_router_dengan_password(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01', 'r02')])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 2]);

        $this->assertSame([], $this->id('r01', 'r02'));
        $this->assertCount(1, $this->id('r03'));
        $this->assertNotContains('r01', $this->diRouter());
        $this->assertNotContains('r02', $this->diRouter());
        $this->assertContains('r03', $this->diRouter());
        $this->assertSame(60, $this->batch->fresh()->quantity);

        $log = ActivityLog::where('action', 'voucher_delete_many')->sole();
        $this->assertSame(['jumlah' => 2, 'mode' => 'pilihan', 'rincian' => ['ready' => 2]], array_intersect_key($log->properties, ['jumlah' => 1, 'mode' => 1, 'rincian' => 1]));
    }

    public function test_hapus_semua_sesuai_filter_lintas_halaman_tanpa_mencabut_kartu_bentrok_dari_router(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'semua' => true, 'status' => 'expired', 'jumlah' => 56])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 56]);

        $this->assertSame(0, $this->batch->vouchers()->status('expired')->count());
        $this->assertSame(6, $this->batch->vouchers()->count());
        $this->assertSame([], array_values(array_filter($this->diRouter(), fn ($n) => str_starts_with($n, 'x'))));
        $this->assertContains('kembar1', $this->diRouter());
        $this->assertContains('a01', $this->diRouter());

        $log = ActivityLog::where('action', 'voucher_delete_many')->sole();
        $this->assertSame(['mode' => 'filter', 'dilewati_bentrok' => 1], array_intersect_key($log->properties, ['mode' => 1, 'dilewati_bentrok' => 1]));
    }

    public function test_ditolak_tanpa_menghapus_bila_password_salah_jumlah_berubah_atau_kartu_batch_lain(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $rute = route('vouchers.destroy-many', $this->batch);

        $this->deleteJson($rute, ['password' => 'salah', 'ids' => $this->id('r01')])->assertStatus(422);
        $this->assertSame(1, ActivityLog::where('action', 'password_confirm_failed')->count());

        $this->deleteJson($rute, ['password' => 'sandi-admin', 'semua' => true, 'status' => 'expired', 'jumlah' => 55])
            ->assertStatus(409)->assertJsonPath('error', fn ($e) => str_contains($e, 'berubah'));

        $this->deleteJson($rute, ['password' => 'sandi-admin', 'ids' => $this->id('a01', 'b01')])->assertStatus(422);

        $this->assertSame(63, $this->batch->vouchers()->count() + $this->lain->vouchers()->count());
        $this->assertCount(63, $this->diRouter());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_delete_many')->count());
    }

    public function test_operator_ditolak_dan_batch_yang_sedang_sinkron_ditolak(): void
    {
        $this->actingAs($this->pengguna('operator'));
        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01')])->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->pengguna('admin'));
        $this->batch->update(['status' => 'syncing']);
        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01')])->assertStatus(409);

        $this->assertCount(1, $this->id('r01'));
    }

    public function test_router_gagal_tidak_ada_kartu_terhapus_dari_panel(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->palsu->skenario(['tolak_login' => true]);

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01', 'r02')])
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->assertCount(2, $this->id('r01', 'r02'));
        $this->assertSame(62, $this->batch->fresh()->quantity);
    }

    public function test_daftar_kartu_punya_centang_dan_info_filter_hanya_untuk_admin(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $isi = $this->get(route('vouchers.show', ['batch' => $this->batch, 'status' => 'expired']))->assertOk()->getContent();

        $this->assertStringContainsString('data-pilih-semua', $isi);
        $this->assertSame(50, substr_count($isi, 'data-pilih '));
        $this->assertMatchesRegularExpression('#data-daftar-info[^>]*data-total="56"#', $isi);
        $this->assertStringContainsString('data-rincian="{&quot;expired&quot;:56}"', $isi);

        $this->flushSession();
        $this->actingAs($this->pengguna('operator'));
        $this->get(route('vouchers.show', $this->batch))->assertOk()->assertDontSee('data-pilih-semua', false)->assertDontSee('data-daftar-info', false);
    }
}
