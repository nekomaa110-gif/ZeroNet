<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VoucherHapusTampilanTest extends TestCase
{
    use RefreshDatabase;

    private VoucherBatch $batch;

    private VoucherBatch $tanpaRouter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();

        $router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->batch = $this->buatBatch('up-201-uji', $router->id);
        $this->tanpaRouter = $this->buatBatch('up-202-tanpa-router', null);
    }

    private function buatBatch(string $kode, ?int $routerId): VoucherBatch
    {
        $batch = VoucherBatch::create([
            'router_id' => $routerId, 'router_name' => 'UJI', 'code' => $kode,
            'profile' => '4-JAM', 'quantity' => 2, 'status' => 'success',
        ]);
        foreach (['k1', 'k2'] as $nama) {
            Voucher::create([
                'batch_id' => $batch->id, 'router_id' => $routerId, 'username' => $kode . $nama, 'password' => 'p',
                'profile' => '4-JAM', 'status' => 'expired', 'sync_status' => 'success',
            ]);
        }

        return $batch;
    }

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('sandi-admin'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_tombol_hapus_habis_hanya_untuk_admin_dan_batch_bertrouter(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->get(route('vouchers.show', $this->batch))->assertOk()
            ->assertSee('data-massal-habis', false)
            ->assertSee('data-hapus-habis-semua', false)
            ->assertSee('Hapus semua Habis');
        $this->get(route('vouchers.show', $this->batch), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('data-massal-habis', false);

        $this->get(route('vouchers.show', $this->tanpaRouter))->assertOk()
            ->assertDontSee('data-massal-habis', false)
            ->assertDontSee('data-hapus-habis-semua', false);
        $this->get(route('vouchers.show', $this->tanpaRouter), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertDontSee('data-massal-habis', false);

        $this->flushSession();
        $this->actingAs($this->pengguna('operator'));
        $this->get(route('vouchers.show', $this->batch))->assertOk()
            ->assertDontSee('data-massal-habis', false)
            ->assertDontSee('data-hapus-habis-semua', false);
    }

    public function test_hapus_batch_router_memakai_tombol_js_bukan_form(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->get(route('vouchers.show', $this->batch))->assertOk()
            ->assertSee('data-hapus-batch-router', false)
            ->assertDontSee('name="remove_from_router" value="1"', false);
    }

    public function test_hapus_dari_panel_saja_tetap_berupa_form(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->get(route('vouchers.show', $this->batch))->assertOk()
            ->assertSee('Hapus dari panel')
            ->assertSee('data-confirm-title="Hapus Batch"', false);
    }

    public function test_halaman_memuat_url_pratinjau(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->get(route('vouchers.show', $this->batch))->assertOk()
            ->assertSee(route('vouchers.preview-destroy-many', $this->batch), false)
            ->assertSee(route('vouchers.preview-destroy', $this->batch), false);
    }

    public function test_daftar_batch_membawa_url_pratinjau_per_batch(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->get(route('vouchers.index'))->assertOk()
            ->assertSee('data-pratinjau="' . route('vouchers.preview-destroy', $this->batch) . '"', false);
    }

    public function test_zeroconfirm_mendukung_detail(): void
    {
        $js = file_get_contents(public_path('assets/confirm-dialog.js'));

        $this->assertStringContainsString('zc-detail', $js);
        $this->assertStringContainsString('opts.detail', $js);
    }
}
