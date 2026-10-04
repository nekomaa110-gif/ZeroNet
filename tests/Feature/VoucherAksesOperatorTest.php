<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VoucherAksesOperatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'username' => $role . 'uji', 'email' => "{$role}@example.test",
            'password' => Hash::make('x'), 'role' => $role, 'is_active' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function batch(): VoucherBatch
    {
        $router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'api', 'password' => 'x',
        ]);

        $batch = VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => 'UJI', 'code' => 'up-001-09.26.26-uji',
            'profile' => '5-JAM', 'quantity' => 1, 'status' => 'success', 'price' => 5000,
        ]);

        Voucher::create([
            'batch_id' => $batch->id, 'router_id' => $router->id, 'username' => 'kode1', 'password' => 'p1',
            'profile' => '5-JAM', 'comment' => $batch->code, 'status' => 'ready', 'sync_status' => 'success',
        ]);

        return $batch;
    }

    public function test_operator_ditolak_server_untuk_generate_sinkron_dan_kirim_ulang(): void
    {
        $batch = $this->batch();
        $this->actingAs($this->pengguna('operator'));

        $this->postJson(route('vouchers.store'), [])->assertForbidden();
        $this->postJson(route('vouchers.reconcile'), ['router' => 'uji'])->assertForbidden();
        $this->postJson(route('vouchers.resync', $batch))->assertForbidden();
        $this->getJson(route('vouchers.router-options', ['router' => 'uji']))->assertForbidden();

        $this->assertSame('success', $batch->fresh()->status);
    }

    public function test_operator_tetap_bisa_melihat_dan_mencetak_tanpa_tombol_tulis(): void
    {
        $batch = $this->batch();
        $this->actingAs($this->pengguna('operator'));

        $this->get(route('vouchers.index'))->assertOk()->assertDontSee('id="vgOpen"', false)->assertDontSee('id="vgSyncBtn"', false);
        $this->get(route('vouchers.show', $batch))->assertOk()->assertDontSee('id="vbResync"', false);
        $this->get(route('vouchers.print', $batch))->assertOk()->assertSee('kode1');
    }

    public function test_hapus_satu_voucher_hanya_admin_dan_tercatat_di_log_aktivitas(): void
    {
        $batch   = $this->batch();
        $voucher = $batch->vouchers()->first();

        $this->actingAs($this->pengguna('operator'));
        $this->deleteJson(route('vouchers.destroy-one', $voucher))->assertForbidden();
        $this->assertNotNull($voucher->fresh());

        $this->flushSession();
        $this->actingAs($this->pengguna('admin'));
        $this->deleteJson(route('vouchers.destroy-one', $voucher))->assertOk()->assertJson(['success' => true]);

        $this->assertNull($voucher->fresh());
        $this->assertSame(0, $batch->fresh()->quantity);

        $log = ActivityLog::where('action', 'voucher_delete')->sole();
        $this->assertSame('Menghapus voucher kode1 dari batch up-001-09.26.26-uji.', $log->description);
        $this->assertSame(['router' => 'uji', 'batch_id' => $batch->id, 'remove_from_router' => false], $log->properties);
        $this->assertSame((string) $voucher->id, (string) $log->subject_id);
    }

    public function test_admin_lolos_otorisasi_generate(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->postJson(route('vouchers.store'), [])->assertStatus(422);
        $this->get(route('vouchers.index'))->assertOk()->assertSee('id="vgOpen"', false);
    }
}
