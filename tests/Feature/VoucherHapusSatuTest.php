<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\PencabutKartuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherHapusSatuTest extends TestCase
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
            ['name' => 'zv1', 'password' => 'p', 'profile' => '5-JAM', 'comment' => 'up-301-uji-satu'],
            ['name' => 'zv1', 'password' => 'lain', 'profile' => '5-JAM', 'comment' => 'up-301-uji-satu'],
            ['name' => 'zv2', 'password' => 'lain', 'profile' => '5-JAM', 'comment' => 'up-301-uji-satu'],
            ['name' => 'zv3', 'password' => 'p', 'profile' => '5-JAM', 'comment' => 'up-301-uji-satu'],
        ]);
        $router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);
        $this->batch = VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => 'UJI', 'code' => 'up-301-uji-satu', 'profile' => '5-JAM', 'quantity' => 3, 'status' => 'success',
        ]);
        foreach (['zv1', 'zv2', 'zv3'] as $nama) {
            Voucher::create([
                'batch_id' => $this->batch->id, 'router_id' => $router->id, 'username' => $nama, 'password' => 'p',
                'comment' => 'up-301-uji-satu', 'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success',
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

    private function kartu(string $nama): Voucher
    {
        return Voucher::where('username', $nama)->sole();
    }

    private function sisa(string $nama): array
    {
        return array_values(array_map(
            fn ($u) => $u['name'] . '/' . $u['password'],
            array_filter($this->palsu->users(), fn ($u) => $u['name'] === $nama),
        ));
    }

    public function test_hapus_satu_mencabut_baris_yang_cocok_saja(): void
    {
        $this->deleteJson(route('vouchers.destroy-one', $this->kartu('zv1')), ['remove_from_router' => true])
            ->assertOk()->assertJson(['success' => true, 'message' => 'Voucher dihapus.']);

        $this->assertSame(0, Voucher::where('username', 'zv1')->count());
        $this->assertSame(['zv1/lain'], $this->sisa('zv1'));
        $this->assertSame('dicabut', ActivityLog::where('action', 'voucher_delete')->sole()->properties['hasil_router']);
    }

    public function test_hapus_satu_kartu_belum_terkirim_tidak_menyentuh_router(): void
    {
        $kartu = $this->kartu('zv3');
        $kartu->update(['sync_status' => 'pending']);

        $this->deleteJson(route('vouchers.destroy-one', $kartu), ['remove_from_router' => true])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(0, Voucher::where('username', 'zv3')->count());
        $this->assertSame(['zv3/p'], $this->sisa('zv3'));
        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame('tidak_dicabut', DeletedVoucher::sole()->hasil_router);
    }

    private function jumlahRemove(): int
    {
        return $this->palsu->hitung()['remove']['/ip/hotspot/user'] ?? 0;
    }

    private function assertKartuTetapAda(string $nama): void
    {
        $this->assertSame(1, Voucher::where('username', $nama)->count());
        $this->assertSame(3, $this->batch->fresh()->quantity);
        $this->assertSame(0, DeletedVoucher::count());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_delete')->count());
    }

    public function test_hapus_satu_beda_password_ditahan_kartu_tetap_ada(): void
    {
        $this->deleteJson(route('vouchers.destroy-one', $this->kartu('zv2')), ['remove_from_router' => true])
            ->assertStatus(422)->assertJson([
                'success' => false,
                'error'   => 'Kartu ditahan: password di router beda dengan panel.',
                'hasil'   => 'beda_password',
            ]);

        $this->assertKartuTetapAda('zv2');
        $this->assertSame(['zv2/lain'], $this->sisa('zv2'));
        $this->assertSame(0, $this->jumlahRemove());
    }

    public function test_hapus_satu_ambigu_ditahan(): void
    {
        $this->palsu->skenario(['kembar_boleh' => true]);
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'zv3', 'password' => 'p', 'profile' => '5-JAM', 'comment' => 'up-301-uji-satu']]);

        $this->deleteJson(route('vouchers.destroy-one', $this->kartu('zv3')), ['remove_from_router' => true])
            ->assertStatus(422)->assertJson([
                'success' => false,
                'error'   => 'Kartu ditahan: ada lebih dari satu user cocok di router.',
                'hasil'   => 'ambigu',
            ]);

        $this->assertKartuTetapAda('zv3');
        $this->assertSame(['zv3/p', 'zv3/p'], $this->sisa('zv3'));
        $this->assertSame(0, $this->jumlahRemove());
    }

    public function test_hapus_satu_beda_batch_ditahan(): void
    {
        $this->palsu->hapusBaris('/ip/hotspot/user', 'zv3');
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'zv3', 'password' => 'p', 'profile' => '5-JAM', 'comment' => 'vc-lain']]);

        $this->deleteJson(route('vouchers.destroy-one', $this->kartu('zv3')), ['remove_from_router' => true])
            ->assertStatus(422)->assertJson([
                'success' => false,
                'error'   => 'Kartu ditahan: user router milik batch lain.',
                'hasil'   => 'beda_batch',
            ]);

        $this->assertKartuTetapAda('zv3');
        $this->assertSame(['zv3/p'], $this->sisa('zv3'));
        $this->assertSame(0, $this->jumlahRemove());
    }

    public function test_hapus_satu_tidak_ada_di_router_tetap_dihapus_dengan_hasil_tidak_ada(): void
    {
        $this->palsu->hapusBaris('/ip/hotspot/user', 'zv3');

        $this->deleteJson(route('vouchers.destroy-one', $this->kartu('zv3')), ['remove_from_router' => true])
            ->assertOk()->assertJson(['success' => true, 'message' => 'Voucher dihapus.']);

        $this->assertSame(0, Voucher::where('username', 'zv3')->count());
        $this->assertSame(2, $this->batch->fresh()->quantity);
        $this->assertSame('tidak_ada', DeletedVoucher::sole()->hasil_router);
        $this->assertSame('tidak_ada', ActivityLog::where('action', 'voucher_delete')->sole()->properties['hasil_router']);
        $this->assertSame(0, $this->jumlahRemove());
    }

    public function test_hapus_satu_koneksi_putus_kartu_tetap_ada(): void
    {
        $this->palsu->skenario(['putus_setelah_remove' => 1]);

        $this->deleteJson(route('vouchers.destroy-one', $this->kartu('zv3')), ['remove_from_router' => true])
            ->assertStatus(422)->assertJson(['success' => false, 'error' => PencabutKartuService::PESAN_PUTUS]);

        $this->assertKartuTetapAda('zv3');
    }

    public function test_hapus_satu_gagal_dicabut_tidak_dihapus(): void
    {
        $this->palsu->skenario(['remove_gagal' => ['zv3']]);

        $this->deleteJson(route('vouchers.destroy-one', $this->kartu('zv3')), ['remove_from_router' => true])
            ->assertStatus(422)->assertJson([
                'success' => false,
                'error'   => 'Kartu ditahan: gagal dicabut dari router.',
                'hasil'   => 'gagal',
            ]);

        $this->assertKartuTetapAda('zv3');
        $this->assertSame(['zv3/p'], $this->sisa('zv3'));
    }
}
