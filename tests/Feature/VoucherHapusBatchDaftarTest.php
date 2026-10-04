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

class VoucherHapusBatchDaftarTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    private Router $router;

    private VoucherBatch $a;

    private VoucherBatch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();

        $this->palsu = RouterPalsu::mulai(array_map(
            fn ($nama) => ['name' => $nama, 'password' => 'p', 'profile' => '4-JAM'],
            ['k1', 'k2', 'k3', 'm1'],
        ));
        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->a = $this->batch('up-101-uji-a', [
            'k1' => ['status' => 'ready', 'sync_status' => 'success'],
            'k2' => ['status' => 'active', 'sync_status' => 'success', 'expired_at' => now()->addHours(3)],
            'k3' => ['status' => 'active', 'sync_status' => 'success', 'expired_at' => now()->subHour()],
        ]);
        $this->b = $this->batch('up-102-uji-b', [
            'm1' => ['status' => 'ready', 'sync_status' => 'failed'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        parent::tearDown();
    }

    private function batch(string $kode, array $kartu): VoucherBatch
    {
        $batch = VoucherBatch::create([
            'router_id' => $this->router->id, 'router_name' => 'UJI', 'code' => $kode,
            'profile' => '4-JAM', 'quantity' => count($kartu), 'status' => 'success',
        ]);
        foreach ($kartu as $nama => $isi) {
            Voucher::create($isi + [
                'batch_id' => $batch->id, 'router_id' => $this->router->id, 'username' => $nama, 'password' => 'p', 'profile' => '4-JAM',
            ]);
        }

        return $batch;
    }

    private function masuk(string $role = 'admin'): void
    {
        $this->actingAs(User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('sandi-admin'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));
    }

    private function diRouter(): array
    {
        return array_column($this->palsu->users(), 'name');
    }

    private static function centang(string $html, int $id): string
    {
        preg_match('#<input[^>]*data-pilih value="' . $id . '"[^>]*>#', $html, $m);

        return $m[0] ?? '';
    }

    public function test_hapus_batch_lewat_json_dengan_password_mencabut_kartu_dari_router(): void
    {
        $this->masuk();

        $this->deleteJson(route('vouchers.destroy', $this->a), ['remove_from_router' => true, 'current_password' => 'sandi-admin'])
            ->assertOk()->assertJson(['success' => true, 'dicabut' => 3]);

        $this->assertNull($this->a->fresh());
        $this->assertSame(['m1'], $this->diRouter());
        $this->assertSame(1, ActivityLog::where('action', 'voucher_batch_delete')->count());
    }

    public function test_hapus_batch_lewat_json_dari_panel_saja_tanpa_password(): void
    {
        $this->masuk();

        $this->deleteJson(route('vouchers.destroy', $this->a))->assertOk()->assertJson(['success' => true, 'dicabut' => 0]);

        $this->assertNull($this->a->fresh());
        $this->assertSame(['k1', 'k2', 'k3', 'm1'], $this->diRouter());
    }

    public function test_hapus_batch_lewat_json_ditolak_dengan_sebab_tanpa_menghapus_apa_pun(): void
    {
        $this->masuk();
        $rute = route('vouchers.destroy', $this->a);

        $this->deleteJson($rute, ['remove_from_router' => true, 'current_password' => 'salah'])
            ->assertStatus(422)->assertJson(['success' => false, 'sebab' => 'password']);
        $this->assertSame(1, ActivityLog::where('action', 'password_confirm_failed')->count());

        $this->b->update(['status' => 'syncing']);
        $this->deleteJson(route('vouchers.destroy', $this->b))->assertStatus(409)->assertJson(['success' => false, 'sebab' => 'sinkron']);

        $this->palsu->skenario(['tolak_login' => true]);
        $this->deleteJson($rute, ['remove_from_router' => true, 'current_password' => 'sandi-admin'])
            ->assertStatus(422)->assertJson(['success' => false, 'sebab' => 'router']);

        $this->assertNotNull($this->a->fresh());
        $this->assertNotNull($this->b->fresh());
        $this->assertSame(4, Voucher::count());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_batch_delete')->count());
    }

    public function test_daftar_batch_punya_centang_berisi_rincian_kartu_hanya_untuk_admin(): void
    {
        $this->b->update(['status' => 'syncing']);
        $this->masuk();

        foreach ([[], ['X-Requested-With' => 'XMLHttpRequest']] as $kepala) {
            $isi = $this->get(route('vouchers.index'), $kepala)->assertOk()->getContent();

            $this->assertStringContainsString('data-pilih-semua', $isi);
            $a = self::centang($isi, $this->a->id);
            $this->assertStringContainsString('data-kode="up-101-uji-a"', $a);
            $this->assertStringContainsString('data-router="UJI"', $a);
            $this->assertStringContainsString('data-kartu="3"', $a);
            $this->assertStringContainsString('data-siap="1"', $a);
            $this->assertStringContainsString('data-aktif="1"', $a);
            $this->assertStringContainsString('data-hapus="' . route('vouchers.destroy', $this->a) . '"', $a);
            $this->assertStringNotContainsString('disabled', $a);

            $b = self::centang($isi, $this->b->id);
            $this->assertStringContainsString('data-siap="0"', $b);
            $this->assertStringContainsString('disabled', $b);
        }

        $this->flushSession();
        $this->masuk('operator');
        $this->get(route('vouchers.index'))->assertOk()->assertSee('up-101-uji-a')->assertDontSee('data-pilih', false)->assertDontSee('data-massal', false);
    }

    public function test_halaman_yang_kosong_setelah_batch_dihapus_menampilkan_halaman_terakhir(): void
    {
        foreach (range(1, 14) as $i) {
            $this->batch(sprintf('up-2%02d-uji-isi', $i), []);
        }
        $this->masuk();

        $isi = $this->get(route('vouchers.index', ['page' => 3]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Belum ada batch voucher', $isi);
        $this->assertStringContainsString('up-101-uji-a', $isi);
        $this->assertStringNotContainsString('up-102-uji-b', $isi);
    }
}
