<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Models\VoucherSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PenjualanTampilanTest extends TestCase
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
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('x'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function data(): void
    {
        $a = Router::create(['slug' => 'div-a', 'name' => 'DIV-A', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);
        $b = Router::create(['slug' => 'div-b', 'name' => 'DIV-B', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);
        $a->forceFill(['last_sales_pull_at' => now()])->saveQuietly();

        $baris = [
            [$a, 'k1', 5000, 6000],
            [$a, 'k2', 5000, null],
            [$b, 'k3', 55000, null],
        ];

        foreach ($baris as $i => [$r, $user, $harga, $jual]) {
            VoucherSale::create([
                'router_id' => $r->id, 'router_name' => $r->name, 'record_hash' => sha1($user), 'record_name' => $user,
                'sold_at' => today()->setTime(10, $i), 'username' => $user, 'profile' => $harga > 5000 ? 'Mingguan' : '5-JAM',
                'price' => $harga, 'sprice' => $jual, 'sprice_sumber' => $jual ? 'profil_saat_tarik' : null,
            ]);
        }
    }

    public function test_admin_melihat_laporan_dengan_harga_jual_belum_diisi_dan_filter_router(): void
    {
        $this->data();
        $this->actingAs($this->pengguna('admin'));

        $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee('Rp 65.000')
            ->assertSee('Pendapatan hari ini')
            ->assertSee('Omzet bulan ini')
            ->assertSee('belum diisi')
            ->assertSee('Data penjualan belum segar untuk DIV-B');

        $this->get(route('penjualan.index', ['router' => 'div-a']))
            ->assertOk()
            ->assertSee('Rp 10.000')
            ->assertDontSee('k3');
    }

    public function test_bawaan_hanya_hari_ini_dalam_satu_tabel_record_semua_router(): void
    {
        $this->data();
        $a = Router::where('slug', 'div-a')->first();
        VoucherSale::create([
            'router_id' => $a->id, 'router_name' => $a->name, 'record_hash' => sha1('kmr1'), 'record_name' => 'kmr1',
            'sold_at' => today()->subDay()->setTime(20, 0), 'username' => 'kmr1', 'profile' => '5-JAM', 'price' => 7000,
        ]);
        $this->actingAs($this->pengguna('admin'));

        $isi = $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee('Rp 65.000')
            ->assertSeeInOrder(['k3', 'k2', 'k1'])
            ->assertSee('DIV-B')
            ->assertDontSee('kmr1')
            ->assertDontSee('>Reset<', false)
            ->getContent();
        $this->assertSame(1, substr_count($isi, '<table'));

        $this->get(route('penjualan.index', ['dari' => today()->subDay()->format('Y-m-d'), 'sampai' => today()->format('Y-m-d')]))
            ->assertOk()
            ->assertSee('kmr1')
            ->assertSee('Rp 72.000')
            ->assertDontSee('>Reset<', false);
    }

    public function test_tanggal_kosong_berarti_hari_ini_dan_label_pendapatan_mengikuti_rentang(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 12:00'));
        $a = Router::create(['slug' => 'div-a', 'name' => 'DIV-A', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);
        $b = Router::create(['slug' => 'div-b', 'name' => 'DIV-B', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);

        foreach ([[$a, 'hi1', '2026-09-30 09:00', 5000], [$a, 'awal1', '2026-09-05 09:00', 7000], [$b, 'lain1', '2026-09-05 10:00', 3000], [$a, 'lalu1', '2026-08-31 09:00', 9000]] as [$r, $u, $w, $h]) {
            VoucherSale::create(['router_id' => $r->id, 'router_name' => $r->name, 'record_hash' => sha1($u), 'record_name' => $u, 'sold_at' => $w, 'username' => $u, 'profile' => '5-JAM', 'price' => $h]);
        }
        $this->actingAs($this->pengguna('admin'));

        $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee('name="dari" value=""', false)->assertSee('name="sampai" value=""', false)
            ->assertSeeInOrder(['Pendapatan hari ini', 'Rp 5.000', 'Omzet bulan ini', 'Rp 15.000'])
            ->assertSeeInOrder(['Record penjualan', 'Unduh CSV', 'Cetak / PDF', 'Semua router'])
            ->assertDontSee('>Kemarin<', false)->assertDontSee('lalu1');

        $this->get(route('penjualan.index', ['router' => 'div-a']))
            ->assertOk()->assertSeeInOrder(['Omzet bulan ini', 'Rp 12.000']);

        $this->get(route('penjualan.index', ['dari' => '2026-09-05']))
            ->assertOk()
            ->assertSeeInOrder(['Pendapatan 5 Sep sampai 30 Sep', 'Rp 15.000'])
            ->assertSee('name="dari" value="2026-09-05"', false)->assertSee('name="sampai" value="2026-09-30"', false);

        $this->get(route('penjualan.index', ['dari' => '2026-09-30', 'sampai' => '2026-09-05']))
            ->assertOk()->assertSeeInOrder(['Pendapatan 5 Sep sampai 30 Sep', 'Rp 15.000'])->assertSee('name="dari" value="2026-09-05"', false);

        $this->get(route('penjualan.index', ['sampai' => '2026-08-31']))
            ->assertOk()->assertSeeInOrder(['Pendapatan ' . Carbon::parse('2026-08-31')->locale('id')->translatedFormat('j M'), 'Rp 9.000'])->assertSee('lalu1');

        $this->get(route('penjualan.index', ['dari' => '2026-09-29', 'sampai' => '2026-09-29']))->assertOk()->assertSee('Pendapatan kemarin');
        $this->get(route('penjualan.index', ['dari' => '2026-09-01', 'sampai' => '2026-09-30']))->assertOk()->assertSee('Pendapatan bulan ini');
    }

    public function test_operator_tidak_bisa_membuka_laporan_dan_tidak_melihat_widget_maupun_tombol_impor(): void
    {
        $this->data();
        $this->actingAs($this->pengguna('operator'));

        $this->get(route('penjualan.index'))->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Penjualan voucher');
        $this->get(route('vouchers.index'))->assertOk()->assertDontSee('Impor dari router');
    }

    public function test_admin_melihat_widget_dashboard_dan_tombol_impor(): void
    {
        $this->data();
        $this->actingAs($this->pengguna('admin'));

        $this->get(route('dashboard'))->assertOk()->assertSee('Penjualan voucher')->assertSee('Rp 65.000')->assertDontSee('dari terdaftar')->assertDontSee('diblokir admin');
        $this->get(route('vouchers.index'))->assertOk()->assertSee('Impor dari router')->assertSee('Total voucher')->assertDontSee('tercatat di panel');
    }
}
