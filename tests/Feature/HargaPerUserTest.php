<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerContact;
use App\Models\CustomerPrice;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\RadCheck;
use App\Models\RadUserGroup;
use App\Models\User;
use App\Services\CustomerPriceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HargaPerUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config(['services.billing.akun_gratis' => ['root']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('x'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function userRadius(string $username, ?string $group = 'Member', ?string $expiry = '20 Oct 2026 23:59:59'): void
    {
        RadCheck::create(['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'rahasia']);

        if ($group) {
            RadUserGroup::create(['username' => $username, 'groupname' => $group, 'priority' => 1]);
        }

        if ($expiry) {
            RadCheck::create(['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => $expiry]);
        }
    }

    private function invoice(string $username, int $amount, string $status): Invoice
    {
        return Invoice::create([
            'slug' => Str::random(20), 'username' => $username, 'profile' => 'Member', 'amount' => $amount,
            'status' => $status, 'due_date' => now()->addDays(5),
            'paid_at' => $status === Invoice::STATUS_PAID ? now()->subDays(30) : null,
        ]);
    }

    private function harga(string $username, int $price): CustomerPrice
    {
        return CustomerPrice::create(['username' => $username, 'price' => $price]);
    }

    private function prices(): CustomerPriceService
    {
        return app(CustomerPriceService::class);
    }

    public static function inputSah(): array
    {
        return [
            'titik ribuan' => ['175.000', 175000],
            'jutaan' => ['1.175.000', 1175000],
            'polos' => ['175000', 175000],
            'spasi tepi' => [' 175.000 ', 175000],
            'batas bawah' => ['1000', 1000],
            'batas atas' => ['99.999.999', 99999999],
            'kosong' => ['', null],
            'nol' => ['0', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('inputSah')]
    public function test_normalisasi_input_sah(mixed $mentah, ?int $hasil): void
    {
        $this->assertSame($hasil, CustomerPriceService::normalisasi($mentah));
    }

    public static function inputTidakSah(): array
    {
        return [
            'negatif' => ['-1000'], 'koma' => ['1,5'], 'desimal koma' => ['175.000,00'], 'dua digit' => ['175.00'],
            'kelompok salah' => ['1.75.000'], 'titik depan' => ['.175'], 'huruf' => ['abc'], 'terlalu besar' => ['100000000'],
            'di bawah minimum' => ['999'], 'array' => [['1']], 'rp' => ['Rp175.000'],
        ];
    }

    #[DataProvider('inputTidakSah')]
    public function test_normalisasi_input_tidak_sah(mixed $mentah): void
    {
        $this->assertFalse(CustomerPriceService::normalisasi($mentah));
    }

    public function test_rupiah(): void
    {
        $this->assertSame('Rp175.000', CustomerPriceService::rupiah(175000));
    }

    public function test_simpan_buat_ubah_sama_hapus_dan_log(): void
    {
        $admin = $this->pengguna('admin');
        $this->actingAs($admin);

        $hasil = $this->prices()->simpan('zzharga', 175000, $admin->id);
        $this->assertSame(['lama' => null, 'baru' => 175000, 'berubah' => true], $hasil);
        $this->prices()->catatPerubahan('zzharga', $hasil);
        $this->assertSame($admin->id, CustomerPrice::where('username', 'zzharga')->value('updated_by'));

        $hasil = $this->prices()->simpan('zzharga', 150000, $admin->id);
        $this->assertSame(['lama' => 175000, 'baru' => 150000, 'berubah' => true], $hasil);
        $this->prices()->catatPerubahan('zzharga', $hasil);

        $hasil = $this->prices()->simpan('zzharga', 150000, $admin->id);
        $this->assertSame(['lama' => 150000, 'baru' => 150000, 'berubah' => false], $hasil);
        $this->prices()->catatPerubahan('zzharga', $hasil);

        $hasil = $this->prices()->simpan('zzharga', null, $admin->id);
        $this->assertSame(['lama' => 150000, 'baru' => null, 'berubah' => true], $hasil);
        $this->prices()->catatPerubahan('zzharga', $hasil);
        $this->assertFalse(CustomerPrice::where('username', 'zzharga')->exists());

        $log = ActivityLog::where('action', 'user_price_update')->orderBy('id')->get();
        $this->assertCount(3, $log);
        $this->assertSame(['price_old' => null, 'price_new' => 175000], $log[0]->properties);
        $this->assertSame(['price_old' => 150000, 'price_new' => null], $log[2]->properties);
    }

    public function test_simpan_null_tanpa_baris_tidak_berubah(): void
    {
        $this->assertSame(['lama' => null, 'baru' => null, 'berubah' => false], $this->prices()->simpan('zzkosong', null, null));
    }

    public function test_simpan_baris_beda_huruf_jadi_update(): void
    {
        $this->harga('BUDI', 150000);

        $hasil = $this->prices()->simpan('budi', 175000, null);

        $this->assertSame(['lama' => 150000, 'baru' => 175000, 'berubah' => true], $hasil);
        $this->assertSame(1, CustomerPrice::count());
        $this->assertSame(175000, CustomerPrice::first()->price);
    }

    public function test_harga_dan_harga_banyak_tidak_peka_huruf(): void
    {
        $this->harga('budi', 150000);

        $this->assertSame(150000, $this->prices()->harga('Budi'));
        $this->assertNull($this->prices()->harga('lain'));
        $this->assertSame(['budi' => 150000], $this->prices()->hargaBanyak(['Budi', 'lain']));
        $this->assertSame([], $this->prices()->hargaBanyak([]));
    }

    public function test_is_gratis_tidak_peka_huruf_dan_bisa_kosong(): void
    {
        $this->assertTrue($this->prices()->isGratis('ROOT'));
        $this->assertFalse($this->prices()->isGratis('budi'));

        config(['services.billing.akun_gratis' => []]);
        $this->assertFalse($this->prices()->isGratis('root'));
    }

    public function test_saran_lewati_lunas_di_luar_rentang(): void
    {
        $lunas = $this->invoice('zzsaran', 175000, Invoice::STATUS_PAID);
        $this->invoice('zzsaran', 0, Invoice::STATUS_PAID);
        $this->invoice('zzsaran', 200000, Invoice::STATUS_UNPAID);

        $saran = $this->prices()->saran('zzsaran');

        $this->assertSame(175000, $saran['amount']);
        $this->assertSame($lunas->id, $saran['invoice_id']);
        $this->assertNull($this->prices()->saran('zzlain'));
    }

    public function test_harga_paket_di_luar_rentang_null(): void
    {
        Package::create(['groupname' => 'Member', 'price' => 200000]);
        Package::create(['groupname' => 'Murah', 'price' => 500]);
        Package::create(['groupname' => 'Nol', 'price' => 0]);

        $this->assertSame(200000, $this->prices()->hargaPaket('Member'));
        $this->assertNull($this->prices()->hargaPaket('Murah'));
        $this->assertNull($this->prices()->hargaPaket('Nol'));
        $this->assertNull($this->prices()->hargaPaket('TanpaPaket'));
        $this->assertNull($this->prices()->hargaPaket(null));
        $this->assertSame(['Member' => 200000, 'Murah' => null, 'TanpaPaket' => null], $this->prices()->petaHargaPaket(['Member', 'Murah', 'TanpaPaket']));
        $this->assertSame(['member' => 200000], $this->prices()->petaHargaPaket(['member']));
    }

    public function test_siklus_terlewat(): void
    {
        $this->userRadius('zzsiklus', 'Member', '13 Oct 2026 23:59:59');
        CustomerContact::create(['username' => 'zzsiklus', 'name' => 'zzsiklus', 'phone' => '6281200000001', 'reminder_sent_at' => now()->subHour()]);

        $this->assertSame('2026-10-13', $this->prices()->siklusTerlewat('zzsiklus')?->toDateString());

        $terbuka = $this->invoice('zzsiklus', 200000, Invoice::STATUS_UNPAID);
        $this->assertNull($this->prices()->siklusTerlewat('zzsiklus'));
        $terbuka->update(['status' => Invoice::STATUS_CANCELLED]);

        CustomerContact::where('username', 'zzsiklus')->update(['reminder_sent_at' => '2026-10-08 10:00:00']);
        $this->assertNull($this->prices()->siklusTerlewat('zzsiklus'));

        $this->assertNull($this->prices()->siklusTerlewat('zztanpakontak'));
    }

    private function tambahUser(User $pelaku, array $isian)
    {
        return $this->actingAs($pelaku)
            ->from(route('user-hotspot.create'))
            ->post(route('user-hotspot.store'), $isian + ['username' => 'zzbaru', 'password' => 'abc123', 'group' => 'Member', 'expiry' => '', 'phone' => '081200000001']);
    }

    private function paketMember(int $price = 200000): void
    {
        Package::create(['groupname' => 'Member', 'price' => $price]);
        \App\Models\RadGroupCheck::create(['groupname' => 'Member', 'attribute' => 'Simultaneous-Use', 'op' => ':=', 'value' => '1']);
    }

    public function test_admin_tambah_user_simpan_harga_diketik(): void
    {
        $this->paketMember();

        $this->tambahUser($this->pengguna('admin'), ['price' => '1.175.000'])->assertRedirect(route('user-hotspot.index'));

        $this->assertSame(1175000, CustomerPrice::where('username', 'zzbaru')->value('price'));
        $this->assertSame(1, ActivityLog::where('action', 'user_price_update')->count());
    }

    public function test_admin_tambah_user_harga_kosong_tanpa_baris(): void
    {
        $this->paketMember();

        $this->tambahUser($this->pengguna('admin'), ['price' => ''])->assertRedirect(route('user-hotspot.index'));

        $this->assertTrue(RadCheck::where('username', 'zzbaru')->exists());
        $this->assertFalse(CustomerPrice::where('username', 'zzbaru')->exists());
    }

    public function test_admin_tanpa_field_price_pakai_harga_paket(): void
    {
        $this->paketMember();

        $this->tambahUser($this->pengguna('admin'), [])->assertRedirect(route('user-hotspot.index'));

        $this->assertSame(200000, CustomerPrice::where('username', 'zzbaru')->value('price'));
    }

    public function test_tambah_user_hapus_baris_yatim(): void
    {
        $this->paketMember();
        $this->harga('ZZBARU', 90000);

        $this->tambahUser($this->pengguna('admin'), ['price' => ''])->assertRedirect(route('user-hotspot.index'));

        $this->assertFalse(CustomerPrice::where('username', 'zzbaru')->exists());
    }

    public function test_operator_tambah_user_pakai_harga_paket_abaikan_input(): void
    {
        $this->paketMember();

        $this->tambahUser($this->pengguna('operator'), ['price' => '5000'])->assertRedirect(route('user-hotspot.index'));

        $this->assertSame(200000, CustomerPrice::where('username', 'zzbaru')->value('price'));
    }

    public function test_operator_paket_500_tanpa_harga(): void
    {
        $this->paketMember(500);

        $this->tambahUser($this->pengguna('operator'), [])->assertRedirect(route('user-hotspot.index'));

        $this->assertTrue(RadCheck::where('username', 'zzbaru')->exists());
        $this->assertFalse(CustomerPrice::where('username', 'zzbaru')->exists());
    }

    public function test_operator_grup_tanpa_paket_tanpa_harga(): void
    {
        $this->tambahUser($this->pengguna('operator'), ['group' => ''])->assertRedirect(route('user-hotspot.index'));

        $this->assertTrue(RadCheck::where('username', 'zzbaru')->exists());
        $this->assertFalse(CustomerPrice::where('username', 'zzbaru')->exists());
    }

    public function test_operator_input_harga_aneh_tidak_galat(): void
    {
        $this->paketMember();

        $this->tambahUser($this->pengguna('operator'), ['price' => 'abc'])->assertRedirect(route('user-hotspot.index'));

        $this->assertSame(200000, CustomerPrice::where('username', 'zzbaru')->value('price'));
    }

    public function test_admin_input_harga_tidak_sah_galat_dan_isian_kembali(): void
    {
        $this->paketMember();

        $this->followingRedirects()
            ->tambahUser($this->pengguna('admin'), ['price' => '175.00'])
            ->assertSee(CustomerPriceService::PESAN_GALAT)
            ->assertSee('value="175.00"', false);

        $this->assertFalse(RadCheck::where('username', 'zzbaru')->exists());
    }

    public function test_admin_input_harga_array_galat(): void
    {
        $this->paketMember();

        $this->tambahUser($this->pengguna('admin'), ['price' => ['175000']])->assertSessionHasErrors('price');

        $this->assertFalse(RadCheck::where('username', 'zzbaru')->exists());
    }

    public function test_tambah_user_gagal_transaksi_tanpa_baris_harga(): void
    {
        $this->paketMember();
        $this->mock(\App\Services\VoucherWaService::class, fn ($m) => $m->shouldReceive('buatKiriman')->andThrow(new \RuntimeException('gagal')));

        $this->tambahUser($this->pengguna('admin'), ['price' => '175.000', 'kirim_voucher' => 'otomatis', 'phone' => '081200000001'])
            ->assertStatus(500);

        $this->assertFalse(RadCheck::where('username', 'zzbaru')->exists());
        $this->assertFalse(CustomerPrice::where('username', 'zzbaru')->exists());
    }

    public function test_form_tambah_user_admin_ada_input_harga(): void
    {
        $this->paketMember();

        $this->actingAs($this->pengguna('admin'))->get(route('user-hotspot.create'))
            ->assertOk()
            ->assertSee('name="price"', false)
            ->assertSee('Kosongkan bila belum ada harga; user tanpa harga tidak ditagih otomatis.');
    }

    public function test_form_tambah_user_operator_tanpa_input_harga(): void
    {
        $this->paketMember();

        $this->actingAs($this->pengguna('operator'))->get(route('user-hotspot.create'))
            ->assertOk()
            ->assertDontSee('name="price"', false)
            ->assertSee('Belum ada harga');
    }

    public function test_simpan_jalur_bentrok_unik(): void
    {
        $sekali = false;
        CustomerPrice::creating(function () use (&$sekali) {
            if (! $sekali) {
                $sekali = true;
                DB::table('customer_prices')->insert(['username' => 'BUDI', 'price' => 150000, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        try {
            $hasil = $this->prices()->simpan('budi', 175000, null);
        } finally {
            CustomerPrice::flushEventListeners();
        }

        $this->assertSame(['lama' => 150000, 'baru' => 175000, 'berubah' => true], $hasil);
        $this->assertSame(1, CustomerPrice::count());
        $this->assertSame(175000, CustomerPrice::first()->price);
    }

    public function test_tambah_user_harga_kosong_bertahan_saat_galat_lain(): void
    {
        $this->paketMember();
        $this->userRadius('zzada');

        $this->followingRedirects()
            ->tambahUser($this->pengguna('admin'), ['username' => 'zzada', 'price' => ''])
            ->assertSee('Username sudah digunakan.')
            ->assertSee('hargaDiketik: true', false)
            ->assertSee("harga: ''", false);
    }

    public function test_simpan_gagal_batalkan_tambah_user(): void
    {
        $this->paketMember();
        $this->partialMock(CustomerPriceService::class, fn ($m) => $m->shouldReceive('simpan')->andThrow(new \RuntimeException('gagal')));

        $this->tambahUser($this->pengguna('admin'), ['price' => '175.000'])->assertStatus(500);

        $this->assertFalse(RadCheck::where('username', 'zzbaru')->exists());
    }

    private function ubahUser(User $pelaku, string $username, array $isian)
    {
        return $this->actingAs($pelaku)
            ->from(route('user-hotspot.edit', $username))
            ->put(route('user-hotspot.update', $username), $isian + ['username' => $username, 'group' => 'Member', 'expiry' => '2026-10-20']);
    }

    private function userBerharga(string $username = 'zzedit', int $price = 175000): void
    {
        $this->userRadius($username);
        $this->harga($username, $price);
    }

    public function test_admin_ubah_harga(): void
    {
        $this->userBerharga();

        $this->ubahUser($this->pengguna('admin'), 'zzedit', ['price' => '150.000'])
            ->assertRedirect(route('user-hotspot.index'))
            ->assertSessionHas('success', fn ($v) => str_contains($v, 'Harga diubah ke Rp150.000.'));

        $this->assertSame(150000, CustomerPrice::where('username', 'zzedit')->value('price'));
        $this->assertSame(['price_old' => 175000, 'price_new' => 150000], ActivityLog::where('action', 'user_price_update')->sole()->properties);
    }

    public function test_admin_hapus_harga(): void
    {
        $this->userBerharga();

        $this->ubahUser($this->pengguna('admin'), 'zzedit', ['price' => ''])
            ->assertSessionHas('success', fn ($v) => str_contains($v, 'Harga dihapus.'));

        $this->assertFalse(CustomerPrice::where('username', 'zzedit')->exists());
        $this->assertSame(1, ActivityLog::where('action', 'user_price_update')->count());
    }

    public function test_put_tanpa_field_price_harga_tetap(): void
    {
        $this->userBerharga();

        $this->ubahUser($this->pengguna('admin'), 'zzedit', [])->assertRedirect(route('user-hotspot.index'));

        $this->assertSame(175000, CustomerPrice::where('username', 'zzedit')->value('price'));
        $this->assertSame(0, ActivityLog::where('action', 'user_price_update')->count());
    }

    public function test_edit_isi_awal_lalu_simpan_ulang_tanpa_log(): void
    {
        $this->userBerharga();
        $admin = $this->pengguna('admin');

        $this->actingAs($admin)->get(route('user-hotspot.edit', 'zzedit'))
            ->assertOk()
            ->assertSee('name="price"', false)
            ->assertSee('value="175.000"', false);

        $this->ubahUser($admin, 'zzedit', ['price' => '175.000'])->assertSessionHasNoErrors();

        $this->assertSame(175000, CustomerPrice::where('username', 'zzedit')->value('price'));
        $this->assertSame(0, ActivityLog::where('action', 'user_price_update')->count());
    }

    public function test_operator_kirim_price_diabaikan(): void
    {
        $this->userBerharga();

        $this->ubahUser($this->pengguna('operator'), 'zzedit', ['price' => 'abc'])->assertRedirect(route('user-hotspot.index'));

        $this->assertSame(175000, CustomerPrice::where('username', 'zzedit')->value('price'));
    }

    public function test_operator_lihat_teks_harga(): void
    {
        $this->userBerharga();

        $this->actingAs($this->pengguna('operator'))->get(route('user-hotspot.edit', 'zzedit'))
            ->assertOk()
            ->assertSee('Rp175.000')
            ->assertDontSee('name="price"', false);
    }

    public function test_akun_gratis_abaikan_price(): void
    {
        $this->userRadius('root', 'admin');
        $admin = $this->pengguna('admin');

        $this->ubahUser($admin, 'root', ['group' => 'admin', 'price' => '50000'])->assertRedirect(route('user-hotspot.index'));
        $this->assertFalse(CustomerPrice::where('username', 'root')->exists());

        $this->actingAs($admin)->get(route('user-hotspot.edit', 'root'))
            ->assertSee('Gratis')
            ->assertDontSee('name="price"', false);
    }

    public function test_invoice_terbuka_batalkan_perubahan_harga_dan_isian_kosong_kembali(): void
    {
        $this->userBerharga();
        $this->invoice('zzedit', 200000, Invoice::STATUS_UNPAID);

        $this->followingRedirects()
            ->ubahUser($this->pengguna('admin'), 'zzedit', ['price' => '', 'expiry' => '2026-11-20'])
            ->assertSee("harga: ''", false);

        $this->assertSame(175000, CustomerPrice::where('username', 'zzedit')->value('price'));
    }

    public function test_saran_tampil_dan_tidak_tersimpan(): void
    {
        $this->userRadius('zzsaran');
        $lunas = $this->invoice('zzsaran', 175000, Invoice::STATUS_PAID);

        $this->actingAs($this->pengguna('admin'))->get(route('user-hotspot.edit', 'zzsaran'))
            ->assertSee("Saran: Rp175.000 dari invoice lunas #{$lunas->id}")
            ->assertSee('Pakai saran');

        $this->assertFalse(CustomerPrice::where('username', 'zzsaran')->exists());
    }

    private function sudahDiingatkan(string $username, string $expiry = '13 Oct 2026 23:59:59'): void
    {
        $this->userRadius($username, 'Member', $expiry);
        CustomerContact::create(['username' => $username, 'name' => $username, 'phone' => '6281200000002', 'reminder_sent_at' => now()->subHour()]);
    }

    public function test_peringatan_siklus_terlewat(): void
    {
        $this->sudahDiingatkan('zzsiklus');

        $this->followingRedirects()
            ->ubahUser($this->pengguna('admin'), 'zzsiklus', ['price' => '175.000', 'expiry' => '2026-10-13'])
            ->assertSee('Reminder masa aktif untuk expire 13 Oct 2026 sudah terkirim tanpa tagihan.')
            ->assertSee(route('billing.create', ['username' => 'zzsiklus']), false);
    }

    public function test_peringatan_siklus_tidak_muncul_bila_ada_invoice_terbuka(): void
    {
        $this->sudahDiingatkan('zzsiklus');
        $this->invoice('zzsiklus', 200000, Invoice::STATUS_UNPAID);

        $this->followingRedirects()
            ->ubahUser($this->pengguna('admin'), 'zzsiklus', ['price' => '175.000', 'expiry' => '2026-10-13'])
            ->assertDontSee('sudah terkirim tanpa tagihan');
    }

    public function test_ganti_nomor_sekaligus_tanpa_peringatan(): void
    {
        $this->sudahDiingatkan('zzsiklus');

        $this->followingRedirects()
            ->ubahUser($this->pengguna('admin'), 'zzsiklus', ['price' => '175.000', 'expiry' => '2026-10-13', 'phone' => '081200000077'])
            ->assertDontSee('sudah terkirim tanpa tagihan');

        $this->assertNull(CustomerContact::where('username', 'zzsiklus')->value('reminder_sent_at'));
    }

    public function test_ganti_nomor_saat_jendela_reminder_lewat_tetap_peringatan(): void
    {
        Carbon::setTestNow('2026-10-13 01:00:00');
        $this->sudahDiingatkan('zzsiklus');

        $this->followingRedirects()
            ->ubahUser($this->pengguna('admin'), 'zzsiklus', ['price' => '175.000', 'expiry' => '2026-10-13', 'phone' => '081200000077'])
            ->assertSee('Reminder masa aktif untuk expire 13 Oct 2026 sudah terkirim tanpa tagihan.');
    }

    public function test_ganti_nomor_dan_perpanjang_tetap_peringatan(): void
    {
        $this->sudahDiingatkan('zzsiklus');

        $this->followingRedirects()
            ->ubahUser($this->pengguna('admin'), 'zzsiklus', ['price' => '175.000', 'expiry' => '2026-11-12', 'phone' => '081200000077'])
            ->assertSee('Reminder masa aktif untuk expire 13 Oct 2026 sudah terkirim tanpa tagihan.');
    }

    public function test_harga_dan_perpanjang_sekaligus_tetap_peringatan(): void
    {
        $this->sudahDiingatkan('zzsiklus');

        $this->followingRedirects()
            ->ubahUser($this->pengguna('admin'), 'zzsiklus', ['price' => '175.000', 'expiry' => '2026-11-12'])
            ->assertSee('Reminder masa aktif untuk expire 13 Oct 2026 sudah terkirim tanpa tagihan.');
    }

    public function test_redirect_kembali_bawa_saringan(): void
    {
        $this->userBerharga();
        $admin = $this->pengguna('admin');

        $this->actingAs($admin)->get(route('user-hotspot.edit', ['radius_user' => 'zzedit', 'harga' => 'kosong', 'status' => 'aktif']))
            ->assertSee('name="kembali[harga]" value="kosong"', false);

        $this->ubahUser($admin, 'zzedit', ['kembali' => ['harga' => 'kosong', 'status' => 'aktif', 'evil' => 'x']])
            ->assertRedirect(route('user-hotspot.index', ['harga' => 'kosong', 'status' => 'aktif']));
    }

    public function test_kembali_saringan_harga_ke_halaman_pertama(): void
    {
        $this->userBerharga();

        $this->ubahUser($this->pengguna('admin'), 'zzedit', ['kembali' => ['harga' => 'kosong', 'page' => '2']])
            ->assertRedirect(route('user-hotspot.index', ['harga' => 'kosong']));
    }

    public function test_kembali_buang_nilai_array(): void
    {
        $this->userBerharga();

        $this->ubahUser($this->pengguna('admin'), 'zzedit', ['kembali' => ['search' => ['x'], 'status' => 'aktif']])
            ->assertRedirect(route('user-hotspot.index', ['status' => 'aktif']));
    }

    public function test_simpan_gagal_batalkan_edit(): void
    {
        $this->userBerharga();
        $this->partialMock(CustomerPriceService::class, fn ($m) => $m->shouldReceive('simpan')->andThrow(new \RuntimeException('gagal')));

        $this->ubahUser($this->pengguna('admin'), 'zzedit', ['price' => '150.000', 'expiry' => '2026-10-25'])->assertStatus(500);

        $this->assertSame('20 Oct 2026 23:59:59', RadCheck::where('username', 'zzedit')->where('attribute', 'Expiration')->value('value'));
    }

    public function test_ubah_nomor_gagal_harga_tetap(): void
    {
        $this->userBerharga();
        $this->partialMock(\App\Services\CustomerContactService::class, fn ($m) => $m->shouldReceive('ubahNomor')->andThrow(new \RuntimeException('gagal')));

        $this->ubahUser($this->pengguna('admin'), 'zzedit', ['price' => '150.000', 'phone' => '081200000009'])->assertStatus(500);

        $this->assertSame(175000, CustomerPrice::where('username', 'zzedit')->value('price'));
    }

    private function tigaUser(): void
    {
        $this->userBerharga('zza', 175000);
        $this->userRadius('zzb');
        $this->userRadius('root', 'admin');
    }

    private function daftar(array $query = [], bool $ajax = false)
    {
        $admin = User::where('role', 'admin')->first() ?? $this->pengguna('admin');

        return $this->actingAs($admin)->get(route('user-hotspot.index', $query), $ajax ? ['X-Requested-With' => 'XMLHttpRequest'] : []);
    }

    public function test_drawer_label_harga(): void
    {
        $this->tigaUser();

        $this->daftar()
            ->assertOk()
            ->assertSee('data-harga-label="Rp175.000"', false)
            ->assertSee('data-harga-label="Belum ada harga"', false)
            ->assertSee('data-harga-label="Gratis"', false)
            ->assertSee('id="dr-harga"', false);
    }

    public function test_saringan_harga_kosong_dan_jumlah(): void
    {
        $this->tigaUser();

        $isi = $this->daftar(['harga' => 'kosong'])->assertOk()->assertSee('data-jumlah="1"', false)->getContent();

        $this->assertStringContainsString('data-username="zzb"', $isi);
        $this->assertStringNotContainsString('data-username="zza"', $isi);
        $this->assertStringNotContainsString('data-username="root"', $isi);
    }

    public function test_saringan_digabung_paket_tanpa_baris_ganda(): void
    {
        $this->tigaUser();

        $isi = $this->daftar(['harga' => 'kosong', 'group' => 'Member'])->getContent();

        $this->assertSame(1, substr_count($isi, 'data-username="zzb"'));
        $this->assertStringNotContainsString('data-username="zza"', $isi);
    }

    public function test_saringan_digabung_status_dan_cari(): void
    {
        $this->tigaUser();
        $this->userRadius('zzc', 'Member', '01 Oct 2026 23:59:59');

        $isi = $this->daftar(['harga' => 'kosong', 'status' => 'aktif'])->getContent();
        $this->assertStringContainsString('data-username="zzb"', $isi);
        $this->assertStringNotContainsString('data-username="zzc"', $isi);

        $isi = $this->daftar(['harga' => 'kosong', 'search' => 'zzc'])->getContent();
        $this->assertStringContainsString('data-username="zzc"', $isi);
        $this->assertStringNotContainsString('data-username="zzb"', $isi);
    }

    public function test_partial_ajax_hormati_harga(): void
    {
        $this->tigaUser();

        $isi = $this->daftar(['harga' => 'kosong'], true)->assertOk()->getContent();

        $this->assertStringContainsString('data-username="zzb"', $isi);
        $this->assertStringNotContainsString('data-username="zza"', $isi);
    }

    public function test_saringan_kosong_pesan_khusus(): void
    {
        $this->userBerharga('zza', 175000);

        $this->daftar(['harga' => 'kosong'])->assertSee('Semua user sudah punya harga.');
    }

    public function test_huruf_besar_kecil_konsisten_di_daftar(): void
    {
        $this->userRadius('Budi');
        $this->harga('budi', 150000);

        $this->daftar()->assertSee('data-harga-label="Rp150.000"', false)->assertSee('data-jumlah="0"', false);
        $this->assertStringNotContainsString('data-username="Budi"', $this->daftar(['harga' => 'kosong'])->getContent());
    }

    public function test_invarian_jumlah_sama_dengan_baris(): void
    {
        $this->userBerharga('zza', 175000);
        foreach (['zzb', 'zzc', 'zzd'] as $u) {
            $this->userRadius($u);
        }

        $isi = $this->daftar(['harga' => 'kosong'])->assertSee('data-jumlah="3"', false)->getContent();

        $this->assertSame(3, substr_count($isi, 'class="user-row'));
    }

    public function test_edit_url_bawa_query(): void
    {
        $this->tigaUser();

        $isi = $this->daftar(['harga' => 'kosong', 'status' => 'aktif'], true)->getContent();

        $this->assertMatchesRegularExpression('/data-edit-url="[^"]*zzb\/edit\?[^"]*harga=kosong[^"]*"/', $isi);
        $this->assertMatchesRegularExpression('/data-edit-url="[^"]*zzb\/edit\?[^"]*status=aktif[^"]*"/', $isi);
    }

    public function test_hapus_user_hapus_harga(): void
    {
        $this->userBerharga('zza', 175000);

        $this->actingAs($this->pengguna('admin'))
            ->from(route('user-hotspot.index'))
            ->delete(route('user-hotspot.destroy', 'zza'), ['current_password' => 'x'])
            ->assertSessionHas('success');

        $this->assertFalse(CustomerPrice::where('username', 'zza')->exists());
    }

    public function test_billing_create_isi_harga_user(): void
    {
        $this->userBerharga('zza', 175000);

        $this->actingAs($this->pengguna('admin'))->get(route('billing.create', ['username' => 'zza']))
            ->assertOk()
            ->assertSee('value="175000"', false)
            ->assertSee('Harga user: Rp175.000');
    }

    public function test_billing_create_tanpa_harga_200000(): void
    {
        $this->userRadius('zzb');

        $this->actingAs($this->pengguna('admin'))->get(route('billing.create', ['username' => 'zzb']))
            ->assertSee('value="200000"', false)
            ->assertSee('Belum ada harga');
    }

    public function test_publish_draft_isi_harga_user(): void
    {
        $this->userBerharga('zza', 172500);
        $draft = $this->invoice('zza', 0, Invoice::STATUS_DRAFT);

        $this->actingAs($this->pengguna('admin'))->get(route('billing.show', $draft))
            ->assertOk()
            ->assertSee('value="172500"', false)
            ->assertDontSee('step="1000"', false);
    }

    public function test_old_amount_menang(): void
    {
        $this->userBerharga('zza', 175000);

        $this->followingRedirects()
            ->actingAs($this->pengguna('admin'))
            ->from(route('billing.create', ['username' => 'zza']))
            ->post(route('billing.store'), ['username' => 'zza', 'amount' => 350000, 'due_date' => '2026-10-01'])
            ->assertSee('value="350000"', false);
    }
}
