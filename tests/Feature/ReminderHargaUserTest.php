<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppImage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\CustomerContact;
use App\Models\CustomerPrice;
use App\Models\Invoice;
use App\Models\RadCheck;
use App\Models\RadUserGroup;
use App\Services\MessageTemplateService;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ReminderHargaUserTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '6281399999999';

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config([
            'services.billing.akun_gratis' => ['root'],
            'services.billing.wa_notifications' => true,
            'services.billing.wa_test_mode' => false,
            'services.billing.admin_notif_wa' => self::ADMIN,
            'services.whatsapp.reminder_hours' => 24,
        ]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function target(string $username, ?int $harga = null, ?string $group = 'Member'): string
    {
        $phone = '62812000000' . str_pad((string) ++$this->urut, 2, '0', STR_PAD_LEFT);

        RadCheck::create(['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'rahasia']);
        RadCheck::create(['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => now()->addDay()->format('d M Y H:i:s')]);

        if ($group) {
            RadUserGroup::create(['username' => $username, 'groupname' => $group, 'priority' => 1]);
        }

        CustomerContact::create(['username' => $username, 'name' => $username, 'phone' => $phone]);

        if ($harga) {
            CustomerPrice::create(['username' => $username, 'price' => $harga]);
        }

        return $phone;
    }

    private function invoice(string $username, int $amount, string $status): Invoice
    {
        return Invoice::create([
            'slug' => Str::random(20), 'username' => $username, 'profile' => 'Member', 'amount' => $amount,
            'status' => $status, 'due_date' => now()->addDays(5),
            'paid_at' => $status === Invoice::STATUS_PAID ? now()->subDays(30) : null,
        ]);
    }

    private function pesanAdmin()
    {
        return Queue::pushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $j) => $j->number === self::ADMIN);
    }

    public function test_harga_ada_buat_invoice_bernominal_harga_dan_profil_grup(): void
    {
        $phone = $this->target('zza', 175000, 'Premium');
        $this->invoice('zza', 200000, Invoice::STATUS_PAID);

        $this->artisan('wa:reminders')->assertExitCode(0);

        $baru = Invoice::where('username', 'zza')->where('status', '!=', Invoice::STATUS_PAID)->sole();
        $this->assertSame(175000, $baru->amount);
        $this->assertSame('Premium', $baru->profile);
        Queue::assertPushed(SendWhatsAppImage::class, fn (SendWhatsAppImage $j) => $j->number === $phone);
        $this->assertNotNull(CustomerContact::where('username', 'zza')->value('reminder_sent_at'));
        $this->assertStringContainsString('zza', $this->pesanAdmin()->sole()->message);
    }

    public function test_tanpa_grup_pakai_default_profile(): void
    {
        $this->target('zza', 175000, null);

        $this->artisan('wa:reminders')->assertExitCode(0);

        $this->assertSame(\App\Services\InvoiceService::DEFAULT_PROFILE, Invoice::where('username', 'zza')->sole()->profile);
    }

    public function test_tanpa_harga_tanpa_invoice_tidak_disebut_ringkasan(): void
    {
        $phone = $this->target('zzb');
        $this->invoice('zzb', 200000, Invoice::STATUS_PAID);

        $this->artisan('wa:reminders')->assertExitCode(0);

        $this->assertSame(0, Invoice::where('username', 'zzb')->where('status', '!=', Invoice::STATUS_PAID)->count());
        Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $j) => $j->number === $phone);
        Queue::assertNotPushed(SendWhatsAppImage::class);
        $this->assertCount(0, $this->pesanAdmin());
        $this->assertNotNull(CustomerContact::where('username', 'zzb')->value('reminder_sent_at'));
    }

    public function test_gratis_tanpa_invoice_walau_ada_invoice_terbuka(): void
    {
        $phoneRoot = $this->target('root', null, 'admin');
        $this->invoice('root', 200000, Invoice::STATUS_UNPAID);
        $this->target('zza', 175000);

        $this->artisan('wa:reminders')->assertExitCode(0);

        Queue::assertNotPushed(SendWhatsAppImage::class, fn (SendWhatsAppImage $j) => $j->number === $phoneRoot);
        Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $j) => $j->number === $phoneRoot);
        $this->assertStringNotContainsString('root', $this->pesanAdmin()->sole()->message);
        $this->assertNotNull(CustomerContact::where('username', 'root')->value('reminder_sent_at'));
    }

    public function test_invoice_terbuka_dipakai_walau_harga_beda(): void
    {
        $this->target('zza', 150000);
        $terbuka = $this->invoice('zza', 200000, Invoice::STATUS_UNPAID);

        $this->artisan('wa:reminders')->assertExitCode(0);

        $this->assertSame([$terbuka->id], Invoice::where('username', 'zza')->pluck('id')->all());
        $this->assertStringContainsString('Rp 200.000', $this->pesanAdmin()->sole()->message);
    }

    public function test_ringkasan_campuran_hanya_berharga(): void
    {
        $this->target('zza', 175000);
        $this->target('zzb');
        $this->target('root', null, 'admin');

        $this->artisan('wa:reminders')->assertExitCode(0);

        $pesan = $this->pesanAdmin()->sole()->message;
        $this->assertStringContainsString('zza', $pesan);
        $this->assertStringNotContainsString('zzb', $pesan);
        $this->assertStringNotContainsString('root', $pesan);
        $this->assertStringContainsString('Total: *1*', $pesan);
    }

    public function test_template_editan_tanpa_kurung_mentah(): void
    {
        MessageTemplateService::save('admin_ringkasan_reminder', "Total {total}, {dengan_tagihan} dengan tagihan, {tanpa_tagihan} tanpa nominal\n{daftar}{blok_manual}selesai");
        $this->target('zza', 175000);

        $this->artisan('wa:reminders')->assertExitCode(0);

        $pesan = $this->pesanAdmin()->sole()->message;
        $this->assertStringNotContainsString('{', $pesan);
        $this->assertStringContainsString('zza', $pesan);
    }

    public function test_dry_label_baru_tanpa_efek_samping(): void
    {
        $this->target('zza', 175000);
        $this->target('zzb');
        $this->target('root', null, 'admin');
        $sebelum = Invoice::count();

        $this->artisan('wa:reminders', ['--dry' => true])
            ->expectsOutputToContain('buat invoice baru Rp 175.000')
            ->expectsOutputToContain('TANPA invoice (belum ada harga)')
            ->expectsOutputToContain('TANPA invoice (akun gratis)')
            ->expectsOutputToContain('Total 3 reminder, 1 dengan tagihan, 1 tanpa harga.')
            ->assertExitCode(0);

        $this->assertSame($sebelum, Invoice::count());
        $this->assertSame(0, CustomerContact::whereNotNull('reminder_sent_at')->count());
        Queue::assertNothingPushed();
    }

    public function test_satu_user_gagal_tidak_hentikan_putaran(): void
    {
        $this->target('zza', 175000);
        $this->target('zzgagal', 175000, str_repeat('g', 60));
        $this->target('zzc', 150000);

        $this->artisan('wa:reminders')->assertExitCode(1);

        $this->assertSame(1, Invoice::where('username', 'zza')->count());
        $this->assertSame(1, Invoice::where('username', 'zzc')->count());
        $this->assertSame(0, Invoice::where('username', 'zzgagal')->count());
        $this->assertNull(CustomerContact::where('username', 'zzgagal')->value('reminder_sent_at'));
        $this->assertNotNull(CustomerContact::where('username', 'zzc')->value('reminder_sent_at'));
    }

    public function test_gagal_dispatch_tidak_cap_reminder_dan_tidak_dobel_invoice(): void
    {
        $this->target('zza', 175000);

        $asli  = app(Dispatcher::class);
        $palsu = Mockery::mock(Dispatcher::class);
        $palsu->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($asli) {
            if ($job instanceof SendWhatsAppImage) {
                throw new \RuntimeException('antrean mati');
            }

            return $asli->dispatch($job);
        });
        $this->app->instance(Dispatcher::class, $palsu);

        $this->artisan('wa:reminders')->assertExitCode(1);

        $this->assertNull(CustomerContact::where('username', 'zza')->value('reminder_sent_at'));
        $this->assertSame(1, Invoice::where('username', 'zza')->count());

        $this->app->instance(Dispatcher::class, $asli);
        $this->artisan('wa:reminders')->assertExitCode(0);

        $this->assertSame(1, Invoice::where('username', 'zza')->count());
        $this->assertNotNull(CustomerContact::where('username', 'zza')->value('reminder_sent_at'));
    }

    public function test_huruf_besar_kecil_reminder(): void
    {
        $this->target('Budi');
        CustomerPrice::create(['username' => 'budi', 'price' => 150000]);

        $this->artisan('wa:reminders')->assertExitCode(0);

        $this->assertSame(150000, Invoice::where('username', 'Budi')->sole()->amount);
    }
}
