<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\RadCheck;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PerpanjangInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Bus::fake();
        Carbon::setTestNow('2026-10-02 10:00:00');
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

    private function userHotspot(string $username = 'zztest', string $expiry = '05 Oct 2026 23:59:59'): void
    {
        RadCheck::create(['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'rahasia']);
        RadCheck::create(['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => $expiry]);
    }

    private function invoice(string $status = Invoice::STATUS_UNPAID, string $username = 'zztest'): Invoice
    {
        return Invoice::create([
            'username' => $username, 'profile' => 'Member', 'amount' => 175000,
            'status' => $status, 'due_date' => '2026-06-25 23:59:59',
        ]);
    }

    private function expiry(string $username = 'zztest'): string
    {
        return (string) RadCheck::where('username', $username)->where('attribute', 'Expiration')->value('value');
    }

    public function test_perpanjang_ditolak_bila_ada_invoice_terbuka_tanpa_pilihan(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->actingAs($this->pengguna('admin'))
            ->from(route('user-hotspot.index'))
            ->post(route('user-hotspot.extend', 'zztest'), ['days' => 30])
            ->assertRedirect(route('user-hotspot.index'))
            ->assertSessionHas('error');

        $this->assertSame('05 Oct 2026 23:59:59', $this->expiry());
        $this->assertSame(Invoice::STATUS_UNPAID, $inv->fresh()->status);
    }

    public function test_perpanjang_dan_tandai_invoice_lunas(): void
    {
        $this->userHotspot();
        $inv   = $this->invoice(Invoice::STATUS_PENDING);
        $admin = $this->pengguna('admin');

        $this->actingAs($admin)
            ->post(route('user-hotspot.extend', 'zztest'), ['days' => 30, 'invoice_aksi' => 'lunas', 'invoice_id' => $inv->id])
            ->assertSessionHas('success');

        $this->assertSame('04 Nov 2026 23:59:59', $this->expiry());

        $inv->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $inv->status);
        $this->assertSame($admin->id, (int) $inv->confirmed_by);
        $this->assertSame('2026-11-04 23:59:59', $inv->extended_to->format('Y-m-d H:i:s'));
        $this->assertNotNull($inv->confirmed_at);
        $this->assertStringContainsString('[LUNAS]', (string) $inv->notes);
    }

    public function test_perpanjang_dan_batalkan_invoice(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->actingAs($this->pengguna('admin'))
            ->post(route('user-hotspot.extend', 'zztest'), ['days' => 30, 'invoice_aksi' => 'batal', 'invoice_id' => $inv->id])
            ->assertSessionHas('success');

        $this->assertSame('04 Nov 2026 23:59:59', $this->expiry());

        $inv->refresh();
        $this->assertSame(Invoice::STATUS_CANCELLED, $inv->status);
        $this->assertNull($inv->confirmed_at);
        $this->assertStringContainsString('[BATAL]', (string) $inv->notes);
    }

    public function test_pilihan_untuk_invoice_yang_sudah_tidak_terbuka_ditolak(): void
    {
        $this->userHotspot();
        $lama = $this->invoice(Invoice::STATUS_PAID);

        $this->actingAs($this->pengguna('admin'))
            ->post(route('user-hotspot.extend', 'zztest'), ['days' => 30, 'invoice_aksi' => 'lunas', 'invoice_id' => $lama->id])
            ->assertSessionHas('error');

        $this->assertSame('05 Oct 2026 23:59:59', $this->expiry());
    }

    public function test_pilihan_untuk_invoice_lain_ditolak(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->actingAs($this->pengguna('admin'))
            ->post(route('user-hotspot.extend', 'zztest'), ['days' => 30, 'invoice_aksi' => 'lunas', 'invoice_id' => $inv->id + 99])
            ->assertSessionHas('error');

        $this->assertSame('05 Oct 2026 23:59:59', $this->expiry());
        $this->assertSame(Invoice::STATUS_UNPAID, $inv->fresh()->status);
    }

    public function test_dua_invoice_terbuka_harus_dirapikan_dulu(): void
    {
        $this->userHotspot();
        $a = $this->invoice();
        $b = $this->invoice();

        $this->actingAs($this->pengguna('admin'))
            ->post(route('user-hotspot.extend', 'zztest'), ['days' => 30, 'invoice_aksi' => 'lunas', 'invoice_id' => $b->id])
            ->assertSessionHas('error');

        $this->assertSame('05 Oct 2026 23:59:59', $this->expiry());
        $this->assertSame(Invoice::STATUS_UNPAID, $a->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $b->fresh()->status);
    }

    public function test_operator_tidak_bisa_menutup_invoice(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->actingAs($this->pengguna('operator'))
            ->post(route('user-hotspot.extend', 'zztest'), ['days' => 30, 'invoice_aksi' => 'lunas', 'invoice_id' => $inv->id])
            ->assertSessionHas('error');

        $this->assertSame('05 Oct 2026 23:59:59', $this->expiry());
        $this->assertSame(Invoice::STATUS_UNPAID, $inv->fresh()->status);
    }

    public function test_perpanjang_tanpa_invoice_terbuka_tetap_jalan_untuk_operator(): void
    {
        $this->userHotspot();
        $this->invoice(Invoice::STATUS_PAID);
        $this->invoice(Invoice::STATUS_CANCELLED);

        $this->actingAs($this->pengguna('operator'))
            ->post(route('user-hotspot.extend', 'zztest'), ['days' => 30])
            ->assertSessionHas('success');

        $this->assertSame('04 Nov 2026 23:59:59', $this->expiry());
    }

    public function test_daftar_user_menyertakan_invoice_terbuka_untuk_drawer(): void
    {
        $this->userHotspot();
        $this->userHotspot('zzlain');
        $inv = $this->invoice(Invoice::STATUS_UNPAID);

        $html = $this->actingAs($this->pengguna('admin'))
            ->get(route('user-hotspot.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-invoice-id="' . $inv->id . '"', $html);
        $this->assertSame(1, substr_count($html, 'data-invoice-id="' . $inv->id . '"'));
        $this->assertStringContainsString('name="invoice_aksi"', $html);
    }

    public function test_admin_tidak_bisa_membuat_invoice_kedua_saat_masih_ada_yang_terbuka(): void
    {
        $this->userHotspot();
        $lama = $this->invoice(Invoice::STATUS_PENDING);

        $this->actingAs($this->pengguna('admin'))
            ->from(route('billing.create'))
            ->post(route('billing.store'), ['username' => 'zztest', 'amount' => 175000, 'due_date' => '2026-10-05', 'profile' => ''])
            ->assertRedirect(route('billing.create'))
            ->assertSessionHasErrors('username');

        $this->assertSame(1, Invoice::where('username', 'zztest')->count());
        $this->assertSame(Invoice::STATUS_PENDING, $lama->fresh()->status);
    }

    public function test_admin_tetap_bisa_membuat_invoice_bila_yang_lama_sudah_selesai(): void
    {
        $this->userHotspot();
        $this->invoice(Invoice::STATUS_PAID);
        $this->invoice(Invoice::STATUS_CANCELLED);

        $this->actingAs($this->pengguna('admin'))
            ->post(route('billing.store'), ['username' => 'zztest', 'amount' => 175000, 'due_date' => '2026-10-05', 'profile' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::where('username', 'zztest')->where('status', Invoice::STATUS_UNPAID)->count());
    }

    private function simpanEdit(User $pelaku, array $isian)
    {
        return $this->actingAs($pelaku)
            ->from(route('user-hotspot.edit', 'zztest'))
            ->put(route('user-hotspot.update', 'zztest'), $isian + ['username' => 'zztest', 'group' => '']);
    }

    private function password(string $username = 'zztest'): string
    {
        return (string) RadCheck::where('username', $username)->where('attribute', 'Cleartext-Password')->value('value');
    }

    public function test_edit_memundurkan_expire_ditolak_bila_ada_invoice_terbuka_tanpa_pilihan(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->simpanEdit($this->pengguna('admin'), ['expiry' => '2026-11-05'])
            ->assertRedirect(route('user-hotspot.edit', 'zztest'))
            ->assertSessionHasErrors('invoice_aksi');

        $this->assertSame('05 Oct 2026 23:59:59', $this->expiry());
        $this->assertSame(Invoice::STATUS_UNPAID, $inv->fresh()->status);
    }

    public function test_edit_memundurkan_expire_dan_tandai_invoice_lunas(): void
    {
        $this->userHotspot();
        $inv   = $this->invoice(Invoice::STATUS_PENDING);
        $admin = $this->pengguna('admin');

        $this->simpanEdit($admin, ['expiry' => '2026-11-05', 'invoice_aksi' => 'lunas', 'invoice_id' => $inv->id])
            ->assertRedirect(route('user-hotspot.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('05 Nov 2026 23:59:59', $this->expiry());

        $inv->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $inv->status);
        $this->assertSame($admin->id, (int) $inv->confirmed_by);
        $this->assertSame('2026-11-05 23:59:59', $inv->extended_to->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('Edit user', (string) $inv->notes);
    }

    public function test_edit_memundurkan_expire_dan_batalkan_invoice(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->simpanEdit($this->pengguna('admin'), ['expiry' => '2026-11-05', 'invoice_aksi' => 'batal', 'invoice_id' => $inv->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('05 Nov 2026 23:59:59', $this->expiry());
        $this->assertSame(Invoice::STATUS_CANCELLED, $inv->fresh()->status);
        $this->assertStringContainsString('[BATAL]', (string) $inv->fresh()->notes);
    }

    public function test_edit_menghapus_expire_dianggap_memperpanjang(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->simpanEdit($this->pengguna('admin'), ['expiry' => ''])
            ->assertSessionHasErrors('invoice_aksi');

        $this->assertSame('05 Oct 2026 23:59:59', $this->expiry());
        $this->assertSame(Invoice::STATUS_UNPAID, $inv->fresh()->status);
    }

    public function test_edit_tanpa_mengubah_expire_tidak_menyentuh_invoice(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->simpanEdit($this->pengguna('operator'), ['expiry' => '2026-10-05', 'password' => 'baru123'])
            ->assertRedirect(route('user-hotspot.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('baru123', $this->password());
        $this->assertSame('05 Oct 2026 23:59:59', $this->expiry());
        $this->assertSame(Invoice::STATUS_UNPAID, $inv->fresh()->status);
    }

    public function test_edit_memajukan_expire_tidak_menyentuh_invoice(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->simpanEdit($this->pengguna('admin'), ['expiry' => '2026-10-03'])
            ->assertSessionHasNoErrors();

        $this->assertSame('03 Oct 2026 23:59:59', $this->expiry());
        $this->assertSame(Invoice::STATUS_UNPAID, $inv->fresh()->status);
    }

    public function test_operator_tidak_bisa_memundurkan_expire_bila_ada_invoice_terbuka(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->simpanEdit($this->pengguna('operator'), ['expiry' => '2026-11-05', 'invoice_aksi' => 'lunas', 'invoice_id' => $inv->id])
            ->assertSessionHasErrors('invoice_aksi');

        $this->assertSame('05 Oct 2026 23:59:59', $this->expiry());
        $this->assertSame(Invoice::STATUS_UNPAID, $inv->fresh()->status);
    }

    public function test_edit_memundurkan_expire_tanpa_invoice_terbuka_tetap_jalan(): void
    {
        $this->userHotspot();
        $this->invoice(Invoice::STATUS_PAID);

        $this->simpanEdit($this->pengguna('operator'), ['expiry' => '2026-11-05'])
            ->assertSessionHasNoErrors();

        $this->assertSame('05 Nov 2026 23:59:59', $this->expiry());
    }

    public function test_halaman_edit_menampilkan_invoice_terbuka(): void
    {
        $this->userHotspot();
        $inv = $this->invoice();

        $this->actingAs($this->pengguna('admin'))
            ->get(route('user-hotspot.edit', 'zztest'))
            ->assertOk()
            ->assertSee('#' . $inv->id . ' · Rp 175.000', false)
            ->assertSee('name="invoice_aksi"', false)
            ->assertSee('name="invoice_id" value="' . $inv->id . '"', false);
    }
}
