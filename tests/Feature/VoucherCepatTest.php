<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherCepatTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->palsu = RouterPalsu::mulai(
            [['name' => 'lama1', 'password' => 'x', 'profile' => '4-JAM', 'comment' => 'up-001-09.01.26-stok']],
            [],
            ['/ip/hotspot/user/profile' => [['name' => '4-JAM', 'on-login' => ':put (",ntfc,5000,1d,5000,,Disable,"); {}']]],
        );
        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
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
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('x'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_voucher_manual_username_password_ditulis_ke_router_dengan_comment_up_seperti_mikhmon(): void
    {
        $this->actingAs($this->pengguna('admin'))
            ->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => '4-JAM', 'username' => 'ganti01', 'password' => '4821', 'comment' => 'ganti voucher', 'limit_uptime' => '5h'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $u = collect($this->palsu->users())->firstWhere('name', 'ganti01');
        $this->assertSame(['4821', '4-JAM', 'up-ganti voucher', '5h'], [$u['password'], $u['profile'], $u['comment'], $u['limit-uptime']]);

        $batch = VoucherBatch::where('code', 'up-ganti voucher')->firstOrFail();
        $this->assertSame([1, 'up', 'success', 5000, '1d'], [$batch->quantity, $batch->mode, $batch->status, $batch->price, $batch->validity]);
        $this->assertSame('success', Voucher::where('username', 'ganti01')->value('sync_status'));
    }

    public function test_tanpa_password_jadi_vc_dan_kode_batch_sama_diberi_akhiran(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => '4-JAM', 'username' => 'tamu1'])->assertOk();
        $this->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => '4-JAM', 'username' => 'tamu2', 'comment' => 'ganti'])->assertOk();
        $this->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => '4-JAM', 'username' => 'tamu3', 'comment' => 'ganti'])->assertOk();

        $u = collect($this->palsu->users())->firstWhere('name', 'tamu1');
        $this->assertSame(['tamu1', 'vc-'], [$u['password'], $u['comment']]);
        $this->assertSame(['vc-tamu1', 'vc-ganti', 'vc-ganti-2'], VoucherBatch::orderBy('id')->pluck('code')->all());
        $this->assertSame('vc-ganti', collect($this->palsu->users())->firstWhere('name', 'tamu3')['comment']);
    }

    public function test_nama_yang_sudah_ada_di_router_atau_profil_asing_ditolak_tanpa_menulis_apa_pun(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => '4-JAM', 'username' => 'lama1'])
            ->assertStatus(422)->assertJsonPath('error', 'Nama "lama1" sudah dipakai di UJI. Nama voucher harus unik.');
        $this->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => 'TIDAK-ADA', 'username' => 'baru1'])
            ->assertStatus(422);
        $this->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => '4-JAM', 'username' => 'a"b'])
            ->assertStatus(422)->assertJsonValidationErrors('username');

        $this->assertSame(0, VoucherBatch::count());
        $this->assertCount(1, $this->palsu->users());
    }

    public function test_operator_tidak_boleh_menambah_voucher_manual(): void
    {
        $this->actingAs($this->pengguna('operator'))
            ->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => '4-JAM', 'username' => 'x1'])
            ->assertForbidden();
        $this->assertCount(1, $this->palsu->users());
    }
}
