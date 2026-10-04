<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherHapusBatchPasswordTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    private VoucherBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();

        $this->palsu = RouterPalsu::mulai([
            ['name' => 'k1', 'password' => 'p', 'profile' => '4-JAM'],
            ['name' => 'k2', 'password' => 'p', 'profile' => '4-JAM'],
        ]);
        $router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);
        $this->batch = VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => 'UJI', 'code' => 'up-176-uji', 'profile' => '4-JAM', 'quantity' => 2, 'status' => 'success',
        ]);
        foreach (['k1', 'k2'] as $nama) {
            Voucher::create([
                'batch_id' => $this->batch->id, 'router_id' => $router->id, 'username' => $nama, 'password' => 'p',
                'profile' => '4-JAM', 'status' => 'ready', 'sync_status' => 'success',
            ]);
        }

        $this->actingAs(User::create([
            'name' => 'admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('sandi-admin'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));
    }

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        parent::tearDown();
    }

    private function diRouter(): array
    {
        return array_column($this->palsu->users(), 'name');
    }

    public function test_hapus_batch_dari_router_ditolak_bila_password_salah_atau_kosong(): void
    {
        $this->delete(route('vouchers.destroy', $this->batch), ['remove_from_router' => 1, 'current_password' => 'salah'])
            ->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'Password salah'));
        $this->delete(route('vouchers.destroy', $this->batch), ['remove_from_router' => 1])
            ->assertSessionHas('error', fn ($pesan) => str_contains($pesan, 'Password salah'));

        $this->assertNotNull($this->batch->fresh());
        $this->assertSame(2, $this->batch->vouchers()->count());
        $this->assertSame(['k1', 'k2'], $this->diRouter());
        $this->assertSame(2, ActivityLog::where('action', 'password_confirm_failed')->count());
    }

    public function test_hapus_batch_dari_router_dengan_password_benar_tetap_berjalan(): void
    {
        $this->delete(route('vouchers.destroy', $this->batch), ['remove_from_router' => 1, 'current_password' => 'sandi-admin'])
            ->assertRedirect(route('vouchers.index'))->assertSessionHas('success');

        $this->assertNull($this->batch->fresh());
        $this->assertSame([], $this->diRouter());
    }

    public function test_hapus_batch_dari_panel_saja_tidak_butuh_password(): void
    {
        $this->delete(route('vouchers.destroy', $this->batch))->assertRedirect(route('vouchers.index'))->assertSessionHas('success');

        $this->assertNull($this->batch->fresh());
        $this->assertSame(['k1', 'k2'], $this->diRouter());
    }
}
