<?php

namespace Tests\Feature;

use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\RadCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataBisnisEnvTest extends TestCase
{
    use RefreshDatabase;

    private const REKENING = [['key' => 'contoh-budi', 'bank' => 'Bank Contoh', 'account' => '1234567890', 'holder' => 'BUDI']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Bus::fake();
    }

    private function denganEnv(array $nilai, callable $fn): mixed
    {
        $lama = [];
        foreach ($nilai as $k => $v) {
            $lama[$k] = [$_SERVER[$k] ?? null, $_ENV[$k] ?? null, getenv($k)];
            if ($v === null) {
                unset($_SERVER[$k], $_ENV[$k]);
                putenv($k);
            } else {
                $_SERVER[$k] = $_ENV[$k] = $v;
                putenv("{$k}={$v}");
            }
        }

        try {
            return $fn();
        } finally {
            foreach ($lama as $k => [$server, $env, $getenv]) {
                if ($server === null) {
                    unset($_SERVER[$k]);
                } else {
                    $_SERVER[$k] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$k]);
                } else {
                    $_ENV[$k] = $env;
                }
                $getenv === false ? putenv($k) : putenv("{$k}={$getenv}");
            }
        }
    }

    private function invoice(): Invoice
    {
        return Invoice::create([
            'username' => 'zztest', 'profile' => 'Member', 'amount' => 175000,
            'status' => Invoice::STATUS_UNPAID, 'due_date' => '2026-06-25 23:59:59',
        ]);
    }

    public function test_rekening_dibaca_dari_env(): void
    {
        $rekening = $this->denganEnv(
            ['BILLING_REKENING' => 'Bank Contoh|1234567890|BUDI SANTOSO; BCA | 9876 | SARI ;rusak|tanpa;'],
            fn () => (require base_path('config/services.php'))['billing']['rekening'],
        );

        $this->assertSame([
            ['key' => 'contoh-budi-santoso', 'bank' => 'Bank Contoh', 'account' => '1234567890', 'holder' => 'BUDI SANTOSO'],
            ['key' => 'bca-sari', 'bank' => 'BCA', 'account' => '9876', 'holder' => 'SARI'],
        ], $rekening);
    }

    public function test_ip_cadangan_l2tp_dari_env(): void
    {
        $cfg = $this->denganEnv(
            ['L2TP_RESERVED' => '9:pool IKEv2 (ipsec.conf: rightsourceip); 12 ;x:salah; 300:luar'],
            fn () => require base_path('config/l2tp.php'),
        );

        $this->assertSame([9 => 'pool IKEv2 (ipsec.conf: rightsourceip)', 12 => 'dicadangkan'], $cfg['reserved']);
    }

    public function test_tanpa_env_nilai_bawaan_tidak_memuat_data_pemilik(): void
    {
        $kunci = [
            'APP_NAME', 'APP_DOMAIN', 'BILLING_DOMAIN', 'BUSINESS_WA', 'ADMIN_NOTIF_WA', 'BILLING_WA_TEST_NUMBER',
            'BILLING_REKENING', 'SITE_EMAIL', 'INVOICE_BRAND', 'VOUCHER_BRAND', 'VOUCHER_LOGIN_URL',
            'L2TP_LOCAL_IP', 'L2TP_RESERVED',
        ];

        $cfg = $this->denganEnv(array_fill_keys($kunci, null), fn () => [
            'app'      => require base_path('config/app.php'),
            'services' => require base_path('config/services.php'),
            'site'     => require base_path('config/site.php'),
            'voucher'  => require base_path('config/voucher.php'),
            'l2tp'     => require base_path('config/l2tp.php'),
        ]);

        $this->assertSame('localhost', $cfg['app']['domain']);
        $this->assertSame('', $cfg['app']['billing_domain']);
        $this->assertSame('', $cfg['services']['billing']['business_wa']);
        $this->assertSame('', $cfg['services']['billing']['admin_notif_wa']);
        $this->assertSame('', $cfg['services']['billing']['wa_test_number']);
        $this->assertSame([], $cfg['services']['billing']['rekening']);
        $this->assertSame('https://localhost', $cfg['services']['billing']['portal_url']);
        $this->assertSame('', $cfg['site']['whatsapp']);
        $this->assertSame('', $cfg['site']['email']);
        $this->assertSame('ZeroNet', $cfg['site']['invoice_brand']);
        $this->assertSame('ZeroNet', $cfg['voucher']['card']['brand']);
        $this->assertSame('hotspot.lan', $cfg['voucher']['card']['login']);
        $this->assertSame('10.255.255.1', $cfg['l2tp']['local_ip']);
        $this->assertSame([], $cfg['l2tp']['reserved']);
    }

    public function test_pelanggan_memilih_rekening_dari_config(): void
    {
        Storage::fake('public');
        config(['services.billing.rekening' => self::REKENING]);
        RadCheck::create(['username' => 'zztest', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'rahasia']);
        CustomerContact::create(['username' => 'zztest', 'name' => 'Uji', 'phone' => '6281234567890']);
        $invoice = $this->invoice();

        $this->post(route('customer.login.attempt'), ['username' => 'zztest', 'password' => 'rahasia']);
        $this->assertAuthenticated('customer');

        $this->get(route('customer.invoice.show', $invoice))->assertOk()->assertSee('1234567890')->assertSee('BUDI');

        $this->post(route('customer.invoice.upload-proof', $invoice), [
            'bank_to' => 'lain', 'payment_proof' => UploadedFile::fake()->image('bukti.png'),
        ])->assertSessionHasErrors('bank_to');

        $this->post(route('customer.invoice.upload-proof', $invoice), [
            'bank_to' => 'contoh-budi', 'payment_proof' => UploadedFile::fake()->image('bukti.png'),
        ])->assertRedirect(route('customer.invoice.show', $invoice));

        $this->assertSame('contoh-budi', $invoice->fresh()->bank_to);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_kuitansi_publik_memakai_rekening_dan_merek_config(): void
    {
        config(['services.billing.rekening' => self::REKENING, 'site.invoice_brand' => 'merek-uji']);
        $invoice = $this->invoice();

        $this->get(route('public.invoice', $invoice->slug))
            ->assertOk()
            ->assertSee('1234567890')
            ->assertSee('a.n. BUDI')
            ->assertSee('merek-uji');
    }

    public function test_form_tagihan_admin_memakai_rekening_config(): void
    {
        $admin = User::create([
            'name' => 'admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('x'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);

        config(['services.billing.rekening' => self::REKENING]);
        $this->actingAs($admin)->get(route('billing.create'))->assertOk()->assertSee('1234567890')->assertSee('a.n. BUDI');

        config(['services.billing.rekening' => []]);
        $this->actingAs($admin)->get(route('billing.create'))->assertOk()->assertSee('BILLING_REKENING');
    }
}
