<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\RadCheck;
use App\Models\User;
use App\Services\CustomerContactService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EditNomorWaUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-08 10:00:00');
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

    private function kontak(string $username, string $phone, ?string $reminder = null): CustomerContact
    {
        return CustomerContact::create([
            'username' => $username, 'name' => $username, 'phone' => $phone, 'reminder_sent_at' => $reminder,
        ]);
    }

    public function test_normalisasi_menerima_tiga_format(): void
    {
        foreach (['0812345678901', '62812345678901', '+62812345678901'] as $mentah) {
            $this->assertSame('62812345678901', CustomerContactService::normalisasi($mentah), $mentah);
        }

        foreach (['0812-3456-7890', '0812 3456 7890', '12345', '', null] as $mentah) {
            $this->assertNull(CustomerContactService::normalisasi($mentah), var_export($mentah, true));
        }
    }

    public function test_ubah_nomor_membuat_kontak_baru_dengan_nama_username(): void
    {
        $hasil = app(CustomerContactService::class)->ubahNomor('zztest', '6281234567890');

        $this->assertSame(['lama' => null, 'baru' => '6281234567890', 'berubah' => true, 'dibuat' => true], $hasil);

        $kontak = CustomerContact::where('username', 'zztest')->first();
        $this->assertSame('zztest', $kontak->name);
        $this->assertSame('6281234567890', $kontak->phone);
    }

    public function test_ubah_nomor_mengganti_nomor_dan_mengosongkan_reminder(): void
    {
        $this->kontak('zztest', '6281111111111', '2026-10-07 09:00:00');

        $hasil = app(CustomerContactService::class)->ubahNomor('zztest', '6282222222222');

        $this->assertSame(['lama' => '6281111111111', 'baru' => '6282222222222', 'berubah' => true, 'dibuat' => false], $hasil);

        $kontak = CustomerContact::where('username', 'zztest')->first();
        $this->assertSame('6282222222222', $kontak->phone);
        $this->assertNull($kontak->reminder_sent_at);
    }

    public function test_ubah_nomor_sama_beda_format_tidak_menulis(): void
    {
        $this->kontak('zztest', '081111111111', '2026-10-07 09:00:00');
        $sebelum = CustomerContact::where('username', 'zztest')->first()->updated_at->toDateTimeString();
        Carbon::setTestNow('2026-10-08 11:00:00');

        $hasil = app(CustomerContactService::class)->ubahNomor('zztest', '6281111111111');

        $this->assertFalse($hasil['berubah']);
        $this->assertFalse($hasil['dibuat']);

        $kontak = CustomerContact::where('username', 'zztest')->first();
        $this->assertSame('081111111111', $kontak->phone);
        $this->assertSame($sebelum, $kontak->updated_at->toDateTimeString());
        $this->assertSame('2026-10-07 09:00:00', $kontak->reminder_sent_at->toDateTimeString());
    }

    public function test_ubah_nomor_bentrok_unique_diperlakukan_sebagai_update(): void
    {
        $sudah = false;
        CustomerContact::creating(function () use (&$sudah) {
            if ($sudah) {
                return;
            }
            $sudah = true;
            DB::table('customer_contacts')->insert([
                'username' => 'zztest', 'name' => 'zztest', 'phone' => '6281111111111',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $hasil = app(CustomerContactService::class)->ubahNomor('zztest', '6282222222222');

        $this->assertSame(['lama' => '6281111111111', 'baru' => '6282222222222', 'berubah' => true, 'dibuat' => false], $hasil);
        $this->assertSame(1, CustomerContact::where('username', 'zztest')->count());
        $this->assertSame('6282222222222', CustomerContact::where('username', 'zztest')->value('phone'));
    }

    public function test_pemakai_lain_mencari_semua_bentuk_nomor(): void
    {
        $this->kontak('zztest', '6281111111111');
        $this->kontak('zzc', '6281111111111');
        $this->kontak('zza', '081111111111');
        $this->kontak('zzb', '+6281111111111');
        $this->kontak('zzlain', '6289999999999');

        $this->assertSame(['zza', 'zzb', 'zzc'], app(CustomerContactService::class)->pemakaiLain('zztest', '6281111111111'));
    }

    public function test_menu_wa_tambah_kontak_tetap_menerima_dan_menolak_format_yang_sama(): void
    {
        $admin = $this->pengguna('admin');

        $this->actingAs($admin)
            ->post(route('whatsapp.contacts.store'), ['username' => 'zztest', 'phone' => '081234567890'])
            ->assertSessionHasNoErrors();
        $this->assertSame('6281234567890', CustomerContact::where('username', 'zztest')->value('phone'));

        $this->actingAs($admin)
            ->post(route('whatsapp.contacts.store'), ['username' => 'zzdua', 'phone' => '0812-3456-7890'])
            ->assertSessionHasErrors('phone');
        $this->assertFalse(CustomerContact::where('username', 'zzdua')->exists());
    }

    public function test_menu_wa_update_kontak_tetap_memakai_aturan_yang_sama(): void
    {
        $admin  = $this->pengguna('admin');
        $kontak = $this->kontak('zztest', '6281111111111');

        $this->actingAs($admin)
            ->patchJson(route('whatsapp.contacts.update', $kontak), ['phone' => '0812-3456'])
            ->assertStatus(422);
        $this->assertSame('6281111111111', $kontak->fresh()->phone);

        $this->actingAs($admin)
            ->patchJson(route('whatsapp.contacts.update', $kontak), ['phone' => '+6281234567899'])
            ->assertOk();
        $this->assertSame('6281234567899', $kontak->fresh()->phone);
    }

    private function userHotspot(string $username = 'zztest'): void
    {
        RadCheck::create(['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'rahasia']);
        RadCheck::create(['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => '05 Oct 2026 23:59:59']);
    }

    private function simpanEdit(?User $pelaku, array $isian)
    {
        $uji = $pelaku ? $this->actingAs($pelaku) : $this;

        return $uji->from(route('user-hotspot.edit', 'zztest'))
            ->put(route('user-hotspot.update', 'zztest'), $isian + ['username' => 'zztest', 'group' => '', 'expiry' => '2026-10-05']);
    }

    private function radcheck(string $attribute): string
    {
        return (string) RadCheck::where('username', 'zztest')->where('attribute', $attribute)->value('value');
    }

    private function logNomor(string $action = 'wa_contact_%')
    {
        return ActivityLog::where('action', 'like', $action)->get();
    }

    public static function peran(): array
    {
        return [['admin'], ['operator']];
    }

    #[DataProvider('peran')]
    public function test_admin_dan_operator_bisa_mengganti_nomor(string $role): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');

        $this->simpanEdit($this->pengguna($role), ['phone' => '+6281234567890'])
            ->assertRedirect(route('user-hotspot.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('6281234567890', CustomerContact::where('username', 'zztest')->value('phone'));
        $this->assertStringContainsString('Nomor WA diubah ke 6281234567890.', session('success'));
    }

    public function test_tamu_tidak_bisa_mengubah_nomor(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');

        $this->simpanEdit(null, ['phone' => '6281234567890'])->assertRedirect(route('login'));

        $this->assertSame('6281111111111', CustomerContact::where('username', 'zztest')->value('phone'));
    }

    public function test_nomor_tidak_valid_ditolak_dan_tidak_ada_yang_tersimpan(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');

        $this->simpanEdit($this->pengguna('admin'), ['phone' => '0812-3456-7890', 'expiry' => '2026-11-05', 'password' => 'baru123'])
            ->assertRedirect(route('user-hotspot.edit', 'zztest'))
            ->assertSessionHasErrors(['phone' => 'Nomor harus format Indonesia: 0812..., 62812..., atau +62812...']);

        $this->assertSame('05 Oct 2026 23:59:59', $this->radcheck('Expiration'));
        $this->assertSame('rahasia', $this->radcheck('Cleartext-Password'));
        $this->assertSame('6281111111111', CustomerContact::where('username', 'zztest')->value('phone'));
    }

    public function test_kolom_kosong_atau_tidak_dikirim_tidak_mengubah_nomor(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');
        $admin = $this->pengguna('admin');

        $this->simpanEdit($admin, ['phone' => ''])->assertSessionHasNoErrors();
        $this->simpanEdit($admin, [])->assertSessionHasNoErrors();

        $this->assertSame('6281111111111', CustomerContact::where('username', 'zztest')->value('phone'));
        $this->assertCount(0, $this->logNomor());
        $this->assertStringNotContainsString('Nomor WA', session('success'));
    }

    public function test_nomor_sama_beda_format_tidak_dicatat(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');

        $this->simpanEdit($this->pengguna('admin'), ['phone' => '081111111111'])->assertSessionHasNoErrors();

        $this->assertCount(0, $this->logNomor());
        $this->assertStringNotContainsString('Nomor WA', session('success'));
    }

    public function test_nomor_berubah_tercatat_di_log(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');

        $this->simpanEdit($this->pengguna('admin'), ['phone' => '6281234567890'])->assertSessionHasNoErrors();

        $log = $this->logNomor();
        $this->assertCount(1, $log);
        $this->assertSame('wa_contact_update', $log[0]->action);
        $this->assertSame('wa_contact', $log[0]->subject_type);
        $this->assertSame('zztest', $log[0]->subject_id);
        $this->assertSame(['phone_old' => '6281111111111', 'phone_new' => '6281234567890', 'sumber' => 'edit_user'], $log[0]->properties);
    }

    public function test_user_tanpa_kontak_dibuatkan_kontak(): void
    {
        $this->userHotspot();

        $this->simpanEdit($this->pengguna('admin'), ['phone' => '081234567890'])->assertSessionHasNoErrors();

        $kontak = CustomerContact::where('username', 'zztest')->first();
        $this->assertSame('zztest', $kontak->name);
        $this->assertSame('6281234567890', $kontak->phone);

        $log = $this->logNomor();
        $this->assertCount(1, $log);
        $this->assertSame('wa_contact_create', $log[0]->action);
        $this->assertSame(['phone' => '6281234567890', 'sumber' => 'edit_user'], $log[0]->properties);
        $this->assertStringContainsString('Nomor WA 6281234567890 disimpan.', session('success'));
    }

    public function test_invoice_terbuka_membatalkan_perubahan_nomor(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');
        Invoice::create([
            'username' => 'zztest', 'profile' => 'Member', 'amount' => 175000,
            'status' => Invoice::STATUS_UNPAID, 'due_date' => '2026-10-05 23:59:59',
        ]);

        $this->simpanEdit($this->pengguna('admin'), ['phone' => '6281234567890', 'expiry' => '2026-11-05'])
            ->assertSessionHasErrors('invoice_aksi');

        $this->assertSame('6281111111111', CustomerContact::where('username', 'zztest')->value('phone'));
        $this->assertCount(0, $this->logNomor());
    }

    public function test_nomor_kembar_tetap_tersimpan_dengan_peringatan(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');
        $this->kontak('zzlain', '6281234567890');

        $this->simpanEdit($this->pengguna('admin'), ['phone' => '6281234567890'])->assertSessionHasNoErrors();

        $this->assertSame('6281234567890', CustomerContact::where('username', 'zztest')->value('phone'));
        $this->assertStringContainsString('Nomor ini juga terdaftar di: zzlain.', session('success'));
    }

    public function test_username_kontak_kembar_di_pesan_tetap_di_escape(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');
        $this->kontak('<img src=x onerror=alert(1)>', '6281234567890');
        $admin = $this->pengguna('admin');

        $this->simpanEdit($admin, ['phone' => '6281234567890'])->assertRedirect(route('user-hotspot.index'));

        $this->actingAs($admin)
            ->get(route('user-hotspot.index'))
            ->assertOk()
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
    }

    public function test_tidak_ada_wa_dan_job_yang_dikirim(): void
    {
        Http::fake();
        Queue::fake();
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');

        $this->simpanEdit($this->pengguna('admin'), ['phone' => '6281234567890'])->assertSessionHasNoErrors();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_halaman_edit_menampilkan_nomor_tersimpan(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281234567890');

        $this->actingAs($this->pengguna('operator'))
            ->get(route('user-hotspot.edit', 'zztest'))
            ->assertOk()
            ->assertSee('name="phone"', false)
            ->assertSee('value="6281234567890"', false)
            ->assertSee('Kosongkan untuk tidak mengubah nomor.')
            ->assertSee('Ubah paket, expire, password, atau nomor WhatsApp.');
    }

    public function test_halaman_edit_kolom_nomor_kosong_untuk_user_tanpa_kontak(): void
    {
        $this->userHotspot();

        $this->actingAs($this->pengguna('admin'))
            ->get(route('user-hotspot.edit', 'zztest'))
            ->assertOk()
            ->assertSee('name="phone" value=""', false)
            ->assertSee('Belum ada nomor tersimpan.');
    }

    public function test_halaman_edit_mempertahankan_isian_saat_galat(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');
        $admin = $this->pengguna('admin');

        $this->simpanEdit($admin, ['phone' => '0812-3456-7890'])->assertRedirect(route('user-hotspot.edit', 'zztest'));

        $this->actingAs($admin)
            ->get(route('user-hotspot.edit', 'zztest'))
            ->assertSee('value="0812-3456-7890"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('<div id="phone-error"', false)
            ->assertSee('Nomor harus format Indonesia: 0812..., 62812..., atau +62812...');
    }
}
