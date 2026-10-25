<?php

namespace Tests\Feature;

use App\Models\CustomerContact;
use App\Models\RadCheck;
use App\Models\User;
use App\Services\CustomerContactService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TambahUserNomorWajibTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-13 10:00:00');
        Queue::fake();
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

    private function tambah(User $pelaku, array $isian)
    {
        return $this->actingAs($pelaku)
            ->from(route('user-hotspot.create'))
            ->post(route('user-hotspot.store'), $isian + ['username' => 'zzbaru', 'password' => 'abc123', 'group' => '', 'expiry' => '', 'phone' => '081234567890']);
    }

    private function userRadius(string $username, ?string $expiry = '20 Oct 2026 23:59:59'): void
    {
        RadCheck::create(['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'rahasia']);

        if ($expiry) {
            RadCheck::create(['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => $expiry]);
        }
    }

    public static function modeDanPeran(): array
    {
        return [
            'manual admin' => ['manual', 'admin'],
            'manual operator' => ['manual', 'operator'],
            'otomatis admin' => ['otomatis', 'admin'],
            'otomatis operator' => ['otomatis', 'operator'],
        ];
    }

    #[DataProvider('modeDanPeran')]
    public function test_tanpa_nomor_ditolak_user_tidak_dibuat(string $mode, string $peran): void
    {
        $this->tambah($this->pengguna($peran), ['kirim_voucher' => $mode, 'phone' => ''])
            ->assertRedirect(route('user-hotspot.create'))
            ->assertSessionHasErrors(['phone' => 'Nomor WhatsApp wajib diisi.']);

        $this->assertFalse(RadCheck::where('username', 'zzbaru')->exists());
    }

    public function test_field_nomor_tidak_terkirim_ditolak(): void
    {
        $this->actingAs($this->pengguna('admin'))
            ->from(route('user-hotspot.create'))
            ->post(route('user-hotspot.store'), ['username' => 'zzbaru', 'password' => 'abc123', 'kirim_voucher' => 'manual'])
            ->assertSessionHasErrors(['phone' => 'Nomor WhatsApp wajib diisi.']);

        $this->assertFalse(RadCheck::where('username', 'zzbaru')->exists());
    }

    public function test_format_nomor_salah_ditolak(): void
    {
        $this->tambah($this->pengguna('admin'), ['kirim_voucher' => 'manual', 'phone' => '0812-3456-7890'])
            ->assertSessionHasErrors('phone');

        $this->assertFalse(RadCheck::where('username', 'zzbaru')->exists());
    }

    public function test_nomor_sah_mode_manual_tersimpan(): void
    {
        $this->tambah($this->pengguna('operator'), ['kirim_voucher' => 'manual', 'phone' => ' +6281234567890 '])
            ->assertSessionHasNoErrors();

        $this->assertSame('6281234567890', CustomerContact::where('username', 'zzbaru')->value('phone'));
    }

    public function test_nomor_kembar_tetap_boleh(): void
    {
        CustomerContact::create(['username' => 'zzlama', 'name' => 'zzlama', 'phone' => '6281234567890']);

        $this->tambah($this->pengguna('admin'), ['kirim_voucher' => 'manual'])
            ->assertSessionHasNoErrors();

        $this->assertSame('6281234567890', CustomerContact::where('username', 'zzbaru')->value('phone'));
    }

    public function test_nomor_tetap_terisi_setelah_galat_lain(): void
    {
        $this->userRadius('zzada');

        $this->followingRedirects()
            ->tambah($this->pengguna('admin'), ['username' => 'zzada', 'kirim_voucher' => 'manual', 'phone' => '081299990000'])
            ->assertSee('Username sudah digunakan.')
            ->assertSee("phone: '081299990000'", false);
    }

    public static function usernameTerlarang(): array
    {
        return [
            'spasi' => ['budi santoso'], 'titik' => ['budi.santoso'], 'at' => ['budi@rumah'], 'plus' => ['budi+1'],
            'garis miring' => ['budi/1'], 'huruf aksen' => ['budí'],
        ];
    }

    #[DataProvider('usernameTerlarang')]
    public function test_username_baru_karakter_terlarang_ditolak(string $username): void
    {
        $this->tambah($this->pengguna('admin'), ['username' => $username, 'kirim_voucher' => 'manual'])
            ->assertSessionHasErrors(['username' => 'Username hanya boleh huruf, angka, - dan _.']);

        $this->assertSame(0, RadCheck::count());
    }

    public function test_username_baru_huruf_angka_strip_garis_bawah_diterima(): void
    {
        $this->tambah($this->pengguna('admin'), ['username' => 'Budi_Rumah-2', 'kirim_voucher' => 'manual'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(RadCheck::where('username', 'Budi_Rumah-2')->exists());
    }

    public function test_username_baru_51_karakter_ditolak_50_diterima(): void
    {
        $admin = $this->pengguna('admin');

        $this->tambah($admin, ['username' => str_repeat('a', 51), 'kirim_voucher' => 'manual'])
            ->assertSessionHasErrors(['username' => 'Username maksimal 50 karakter.']);

        $this->tambah($admin, ['username' => str_repeat('b', 50), 'kirim_voucher' => 'manual'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, RadCheck::where('attribute', 'Cleartext-Password')->count());
    }

    public function test_user_lama_panjang_dan_bertitik_tetap_bisa_diedit(): void
    {
        $admin = $this->pengguna('admin');

        foreach ([str_repeat('c', 55), 'lama.titik'] as $username) {
            $this->userRadius($username);

            $this->actingAs($admin)
                ->from(route('user-hotspot.edit', $username))
                ->put(route('user-hotspot.update', $username), ['username' => $username, 'group' => '', 'expiry' => '2026-10-20'])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('user-hotspot.index'));
        }
    }

    public function test_form_tambah_pakai_cek_browser_bukan_bawaan(): void
    {
        $isi = $this->actingAs($this->pengguna('admin'))->get(route('user-hotspot.create'))->assertOk()->getContent();

        $this->assertStringContainsString('novalidate', $isi);
        $this->assertStringNotContainsString(':required="kirimVoucher', $isi);
        $this->assertStringContainsString(substr(json_encode(trim(CustomerContactService::NOMOR_REGEX, '/')), 1, -1), $isi);
        $this->assertStringContainsString(substr(json_encode(trim(\App\Http\Requests\Admin\RadiusUserRequest::USERNAME_BARU_REGEX, '/')), 1, -1), $isi);
        $this->assertStringContainsString('Wajib. Dipakai untuk tagihan, pengingat masa aktif, dan kirim voucher.', $isi);
        $this->assertStringContainsString('Huruf, angka, - dan _. Tanpa spasi, maksimal 50 karakter.', $isi);
    }

    public function test_peringatan_karakter_tidak_didukung_merah_di_atas_petunjuk(): void
    {
        $isi = $this->actingAs($this->pengguna('admin'))->get(route('user-hotspot.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Karakter tidak didukung!', $isi);
        $this->assertStringNotContainsString('Spasi dan karakter selain huruf', $isi);
        $this->assertMatchesRegularExpression('/<small class="err-line" x-show="catatanUsername"[^>]*><\/small>\s*<small class="hint" id="username-hint">/', $isi);
    }

    private function tigaStatus(): void
    {
        $this->userRadius('zzaktif', '20 Oct 2026 23:59:59');
        $this->userRadius('zzexpired', '01 Oct 2026 23:59:59');
        $this->userRadius('zznonaktif', '20 Oct 2026 23:59:59');
        RadCheck::create(['username' => 'zznonaktif', 'attribute' => 'Auth-Type', 'op' => ':=', 'value' => 'Reject']);
    }

    private function formHapus(string $isi, string $username): array
    {
        preg_match_all('#<form[^>]*action="' . preg_quote(route('user-hotspot.destroy', $username), '#') . '"[^>]*>#', $isi, $m);

        return $m[0];
    }

    public function test_tombol_hapus_admin_tampil_di_semua_status(): void
    {
        $this->tigaStatus();

        $isi = $this->actingAs($this->pengguna('admin'))->get(route('user-hotspot.index'))->assertOk()->getContent();

        foreach (['zzaktif', 'zzexpired', 'zznonaktif'] as $u) {
            $form = $this->formHapus($isi, $u);
            $this->assertCount(1, $form, $u);
            $this->assertStringContainsString('onclick="event.stopPropagation()"', $form[0], $u);
            $this->assertStringContainsString('data-confirm-password="1"', $form[0], $u);
        }
    }

    public function test_tombol_hapus_tidak_tampil_untuk_operator(): void
    {
        $this->tigaStatus();

        $isi = $this->actingAs($this->pengguna('operator'))->get(route('user-hotspot.index'))->assertOk()->getContent();

        foreach (['zzaktif', 'zzexpired', 'zznonaktif'] as $u) {
            $this->assertCount(0, $this->formHapus($isi, $u), $u);
        }
    }

    public function test_tombol_aktifkan_nonaktifkan_tetap_hanya_untuk_yang_tidak_aktif(): void
    {
        $this->tigaStatus();

        $isi = $this->actingAs($this->pengguna('admin'))->get(route('user-hotspot.index'))->getContent();

        $this->assertStringNotContainsString(route('user-hotspot.toggle', 'zzaktif'), $isi);
        $this->assertStringContainsString(route('user-hotspot.toggle', 'zzexpired'), $isi);
        $this->assertStringContainsString(route('user-hotspot.toggle', 'zznonaktif'), $isi);
    }
}
