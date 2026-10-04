<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\VoucherPenjualanController;
use App\Models\Router;
use App\Models\User;
use App\Models\VoucherSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PenjualanLaporanTest extends TestCase
{
    use RefreshDatabase;

    private Router $a;

    private Router $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->a = Router::create(['slug' => 'div-a', 'name' => 'DIV-A', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);
        $this->b = Router::create(['slug' => 'div-b', 'name' => 'DIV-B', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);

        $baris = [
            [$this->a, 'kemarin1', today()->subDay()->setTime(9, 0), 5000, '5-JAM'],
            [$this->a, 'kemarin2', today()->subDay()->setTime(22, 0), 5000, '5-JAM'],
            [$this->b, 'kemarin3', today()->subDay()->setTime(12, 0), 55000, 'Mingguan'],
            [$this->a, 'hariini1', today()->setTime(8, 0), 5000, '5-JAM'],
            [$this->b, 'lusa1', today()->subDays(2)->setTime(8, 0), 5000, '5-JAM'],
        ];

        foreach ($baris as [$r, $user, $waktu, $harga, $profil]) {
            VoucherSale::create([
                'router_id' => $r->id, 'router_name' => $r->name, 'record_hash' => sha1($user), 'record_name' => $user,
                'sold_at' => $waktu, 'username' => $user, 'profile' => $profil, 'price' => $harga,
            ]);
        }
    }

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('x'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_cetak_mengikuti_saringan_dan_merinci_per_router_profil_hari(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $kemarin = today()->subDay()->format('Y-m-d');

        $isi = $this->get(route('penjualan.cetak', ['dari' => $kemarin, 'sampai' => $kemarin]))
            ->assertOk()
            ->assertSee('Laporan Penjualan Voucher')->assertSee('Semua router')
            ->assertSee('Rp 65.000')->assertSee('kemarin1')->assertSee('kemarin3')
            ->assertDontSee('hariini1')->assertDontSee('lusa1')->assertDontSee('Per hari')
            ->getContent();
        $this->assertStringContainsString('Cetak / Simpan PDF', $isi);

        $this->get(route('penjualan.cetak', ['router' => 'div-b', 'dari' => today()->subDays(2)->format('Y-m-d'), 'sampai' => $kemarin]))
            ->assertOk()->assertSee('DIV-B')->assertSee('Per hari')->assertSee('Rp 60.000')
            ->assertSee('lusa1')->assertDontSee('kemarin1');
    }

    public function test_cetak_terlalu_banyak_baris_diarahkan_ke_csv_dan_operator_ditolak(): void
    {
        $sekarang = now();
        $baris = [];
        for ($i = 0; $i <= VoucherPenjualanController::CETAK_MAKS_BARIS; $i++) {
            $baris[] = ['router_id' => $this->a->id, 'router_name' => 'DIV-A', 'record_hash' => sha1("m{$i}"), 'record_name' => "m{$i}",
                'sold_at' => today()->setTime(12, 0), 'username' => "m{$i}", 'profile' => '5-JAM', 'price' => 5000,
                'created_at' => $sekarang, 'updated_at' => $sekarang];
        }
        foreach (array_chunk($baris, 500) as $potong) {
            DB::table('voucher_sales')->insert($potong);
        }

        $this->actingAs($this->pengguna('admin'));
        $this->get(route('penjualan.cetak'))->assertOk()->assertSee('terlalu banyak untuk dicetak')->assertDontSee('m2999');

        $this->flushSession();
        $this->actingAs($this->pengguna('operator'));
        $this->get(route('penjualan.cetak'))->assertRedirect(route('dashboard'));
    }

    public function test_ringkasan_harian_memuat_penjualan_voucher_kemarin_per_router(): void
    {
        $this->assertSame(0, Artisan::call('wa:daily-summary', ['--dry' => true]));
        $pesan = Artisan::output();

        $this->assertStringContainsString("🎟️ *Voucher Kemarin*\n3 terjual (*Rp 65.000*)\nDIV-A: 2 (Rp 10.000)\nDIV-B: 1 (Rp 55.000)", $pesan);
        $this->assertStringContainsString('[DRY] Tidak dikirim.', $pesan);
    }
}
