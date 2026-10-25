<?php

namespace Tests\Feature;

use App\Models\CustomerContact;
use App\Models\RadCheck;
use App\Models\User;
use App\Models\WaVoucherDelivery;
use App\Services\RadiusUserService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\WhatsAppService;
use App\Services\VoucherWaService;
use App\Jobs\SendVoucherWhatsApp;
use App\Exceptions\VoucherMasihDikirim;
use App\Models\ActivityLog;
use App\Models\RadUserGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KirimVoucherWaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-09 10:00:00');
        config([
            'services.billing.wa_notifications' => true,
            'services.billing.wa_test_mode'     => false,
            'services.billing.wa_test_number'   => '6281200099999',
        ]);
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

    private function userHotspot(string $username = 'zztest', string $password = 'rahasia'): void
    {
        RadCheck::create(['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password]);
        RadCheck::create(['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => '05 Nov 2026 23:59:59']);
    }

    private function kontak(string $username, string $phone): CustomerContact
    {
        return CustomerContact::create(['username' => $username, 'name' => $username, 'phone' => $phone]);
    }

    private function kiriman(string $status, string $username = 'zztest', ?string $diubah = null): WaVoucherDelivery
    {
        $d = WaVoucherDelivery::create(['username' => $username, 'phone' => '6281234567890', 'status' => $status]);

        if ($diubah !== null) {
            WaVoucherDelivery::whereKey($d->id)->update(['updated_at' => $diubah, 'created_at' => $diubah]);
            $d->refresh();
        }

        return $d;
    }

    public function test_model_basi_mengikuti_batas_15_dan_5_menit(): void
    {
        $this->assertFalse($this->kiriman(WaVoucherDelivery::ANTRE, 'a1', '2026-10-09 09:46:00')->isBasi());
        $this->assertTrue($this->kiriman(WaVoucherDelivery::ANTRE, 'a2', '2026-10-09 09:44:00')->isBasi());
        $this->assertFalse($this->kiriman(WaVoucherDelivery::DIPROSES, 'a3', '2026-10-09 09:56:00')->isBasi());
        $this->assertTrue($this->kiriman(WaVoucherDelivery::DIPROSES, 'a4', '2026-10-09 09:54:00')->isBasi());
        $this->assertFalse($this->kiriman(WaVoucherDelivery::TERKIRIM, 'a5', '2026-10-01 09:00:00')->isBasi());

        $this->assertTrue($this->kiriman(WaVoucherDelivery::ANTRE, 'a6')->isAktif());
        $this->assertTrue($this->kiriman(WaVoucherDelivery::DIPROSES, 'a7')->isAktif());
        $this->assertFalse($this->kiriman(WaVoucherDelivery::GAGAL, 'a8')->isAktif());
    }

    public function test_hapus_user_menghapus_riwayat_kiriman(): void
    {
        $this->userHotspot();
        $this->kiriman(WaVoucherDelivery::TERKIRIM);
        $this->kiriman(WaVoucherDelivery::GAGAL, 'zzlain');

        app(RadiusUserService::class)->delete('zztest');

        $this->assertSame(0, WaVoucherDelivery::where('username', 'zztest')->count());
        $this->assertSame(1, WaVoucherDelivery::where('username', 'zzlain')->count());
    }

    public function test_html_gambar_berisi_kredensial_ter_escape_tanpa_skrip(): void
    {
        $html = view('user-hotspot.voucher-gambar', ['user' => ['username' => 'zz<b>&"x', 'password' => "p<w>&'1"]])->render();

        $this->assertStringStartsWith('<!doctype html>', ltrim($html));
        $this->assertStringContainsString('zz&lt;b&gt;&amp;&quot;x', $html);
        $this->assertStringContainsString('p&lt;w&gt;&amp;&#039;1', $html);
        $this->assertStringNotContainsString('zz<b>', $html);
        $this->assertStringNotContainsString('p<w>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('class="bar"', $html);
        $this->assertStringContainsString('class="voucher"', $html);
    }

    public function test_halaman_cetak_tetap_berisi_tombol_cetak_dan_kartu(): void
    {
        $this->userHotspot('zztest', 'sandi123');

        $this->actingAs($this->pengguna('admin'))
            ->get(route('user-hotspot.voucher', 'zztest'))
            ->assertOk()
            ->assertSee('onclick="window.print()"', false)
            ->assertSee('class="voucher"', false)
            ->assertSee('class="bar"', false)
            ->assertSee('zztest')
            ->assertSee('sandi123');
    }

    public function test_send_image_html_mengirim_body_yang_benar(): void
    {
        Http::fake(['127.0.0.1:9/*' => Http::response(['ok' => true, 'id' => 'ABC', 'png_bytes' => 1234], 200)]);

        $hasil = app(WhatsAppService::class)->sendImageHtml('6281234567890', '<p>x</p>', 'halo', ['width' => 402, 'scale' => 2]);

        $this->assertTrue($hasil['ok']);
        $this->assertSame(200, $hasil['http']);
        $this->assertNull($hasil['transport']);
        $this->assertSame('ABC', $hasil['body']['id']);
        Http::assertSent(fn ($r) => $r->url() === 'http://127.0.0.1:9/send-image-html'
            && $r->hasHeader('x-api-key', 'kunci-tes-palsu-000000000000')
            && $r['number'] === '6281234567890'
            && $r['html'] === '<p>x</p>'
            && $r['caption'] === 'halo'
            && $r['viewport'] === ['width' => 402, 'scale' => 2]);
    }

    public static function galatTransport(): array
    {
        return [
            'ditolak' => ['cURL error 7: Failed to connect to 127.0.0.1 port 3001', 'refused'],
            'timeout' => ['cURL error 28: Operation timed out after 60001 milliseconds', 'timeout'],
            'kosong'  => ['cURL error 52: Empty reply from server', 'putus'],
            'reset'   => ['cURL error 56: Recv failure: Connection reset by peer', 'putus'],
            'lain'    => ['sesuatu yang aneh', 'lain'],
        ];
    }

    #[DataProvider('galatTransport')]
    public function test_send_image_html_mengenali_jenis_galat_transport(string $pesan, string $jenis): void
    {
        Http::fake(fn () => throw new ConnectionException($pesan));

        $hasil = app(WhatsAppService::class)->sendImageHtml('6281234567890', '<p>x</p>');

        $this->assertFalse($hasil['ok']);
        $this->assertNull($hasil['http']);
        $this->assertNull($hasil['body']);
        $this->assertSame($jenis, $hasil['transport']);
    }

    public function test_send_image_html_jawaban_bukan_json(): void
    {
        Http::fake(['127.0.0.1:9/*' => Http::response('Cannot POST /send-image-html', 404, ['Content-Type' => 'text/html'])]);

        $hasil = app(WhatsAppService::class)->sendImageHtml('6281234567890', '<p>x</p>');

        $this->assertFalse($hasil['ok']);
        $this->assertSame(404, $hasil['http']);
        $this->assertNull($hasil['body']);
        $this->assertNull($hasil['transport']);
    }

    public function test_log_send_image_html_tidak_memuat_html(): void
    {
        Log::spy();
        Http::fakeSequence('127.0.0.1:9/*')
            ->push(['ok' => true, 'id' => 'A', 'png_bytes' => 9], 200)
            ->pushStatus(500);

        $wa = app(WhatsAppService::class);
        $wa->sendImageHtml('6281234567890', '<b>SANDI-RAHASIA-123</b>', 'cap-RAHASIA');
        $wa->sendImageHtml('6281234567890', '<b>SANDI-RAHASIA-123</b>', 'cap-RAHASIA');
        Http::fake(fn () => throw new ConnectionException('cURL error 7: gagal'));
        $wa->sendImageHtml('6281234567890', '<b>SANDI-RAHASIA-123</b>', 'cap-RAHASIA');

        foreach (['info', 'error', 'warning', 'debug'] as $level) {
            Log::shouldNotHaveReceived($level, fn (...$args) => str_contains(json_encode($args), 'RAHASIA'));
        }
        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    private function siapKirim(string $password = 'rahasia', string $phone = '6281234567890'): WaVoucherDelivery
    {
        $this->userHotspot('zztest', $password);
        $this->kontak('zztest', $phone);

        return WaVoucherDelivery::create(['username' => 'zztest', 'phone' => $phone, 'status' => WaVoucherDelivery::ANTRE]);
    }

    public static function tabelTujuan(): array
    {
        return [
            'notifikasi mati'        => [false, false, '6281200099999', '6281234567890', null, 'Notifikasi WA sedang dimatikan'],
            'mode tes nomor kosong'  => [true, true, '', '6281234567890', null, 'Mode tes WA aktif tapi nomor tes kosong'],
            'mode tes'               => [true, true, '081200099999', '6281234567890', '6281200099999', null],
            'tanpa nomor kontak'     => [true, false, '6281200099999', null, null, 'User belum punya nomor WhatsApp'],
            'normal'                 => [true, false, '6281200099999', '081234567890', '6281234567890', null],
        ];
    }

    #[DataProvider('tabelTujuan')]
    public function test_tujuan_mengikuti_tabel_spec(bool $notif, bool $tes, string $nomorTes, ?string $kontak, ?string $harapNomor, ?string $harapAlasan): void
    {
        config([
            'services.billing.wa_notifications' => $notif,
            'services.billing.wa_test_mode'     => $tes,
            'services.billing.wa_test_number'   => $nomorTes,
        ]);
        if ($kontak !== null) {
            $this->kontak('zztest', $kontak);
        }

        $this->assertSame(['phone' => $harapNomor, 'reason' => $harapAlasan], app(VoucherWaService::class)->tujuan('zztest'));
    }

    public function test_buat_kiriman_tanpa_tujuan_langsung_gagal_tanpa_job(): void
    {
        Queue::fake();
        config(['services.billing.wa_notifications' => false]);
        $this->userHotspot();
        $this->kontak('zztest', '6281234567890');

        $d = app(VoucherWaService::class)->buatKiriman('zztest', null);

        $this->assertSame(WaVoucherDelivery::GAGAL, $d->status);
        $this->assertSame('Notifikasi WA sedang dimatikan', $d->reason);
        Queue::assertNothingPushed();
    }

    public function test_buat_kiriman_dengan_tujuan_antre(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '081234567890');

        $d = app(VoucherWaService::class)->buatKiriman('zztest', 7);

        $this->assertSame(WaVoucherDelivery::ANTRE, $d->status);
        $this->assertSame('6281234567890', $d->phone);
        $this->assertSame(7, (int) $d->created_by);
    }

    public static function jawabanGateway(): array
    {
        $baris = json_decode(file_get_contents(__DIR__ . '/../Fixtures/gateway-jawaban.json'), true, 512, JSON_THROW_ON_ERROR);
        $hasil = [];
        foreach ($baris as $b) {
            $hasil[$b['nama']] = [$b['http'], $b['body'], $b['status_panel'], $b['alasan_panel']];
        }

        return $hasil;
    }

    #[DataProvider('jawabanGateway')]
    public function test_kirim_memetakan_setiap_jawaban_gateway(int $http, array|string $body, string $status, ?string $alasan): void
    {
        $d = $this->siapKirim();
        Http::fake(['127.0.0.1:9/*' => is_array($body)
            ? Http::response($body, $http)
            : Http::response($body, $http, ['Content-Type' => 'text/html'])]);

        app(VoucherWaService::class)->kirim($d->id);

        $d->refresh();
        $this->assertSame($status, $d->status);
        $this->assertSame($alasan, $d->reason);
        if ($status === WaVoucherDelivery::TERKIRIM) {
            $this->assertSame('MSG1', $d->message_id);
            $this->assertNotNull($d->sent_at);
        }
    }

    public static function galatTransportKirim(): array
    {
        return [
            'gateway mati' => ['cURL error 7: Failed to connect', WaVoucherDelivery::GAGAL, 'Gateway WA tidak bisa dihubungi'],
            'timeout'      => ['cURL error 28: Operation timed out', WaVoucherDelivery::BELUM_PASTI, 'Hasil kiriman tidak pasti; pesan mungkin sudah sampai'],
            'putus'        => ['cURL error 56: Recv failure', WaVoucherDelivery::BELUM_PASTI, 'Hasil kiriman tidak pasti; pesan mungkin sudah sampai'],
            'lain'         => ['sesuatu', WaVoucherDelivery::BELUM_PASTI, 'Hasil kiriman tidak pasti; pesan mungkin sudah sampai'],
        ];
    }

    #[DataProvider('galatTransportKirim')]
    public function test_kirim_memetakan_galat_transport(string $pesan, string $status, string $alasan): void
    {
        $d = $this->siapKirim();
        Http::fake(fn () => throw new ConnectionException($pesan));

        app(VoucherWaService::class)->kirim($d->id);

        $d->refresh();
        $this->assertSame($status, $d->status);
        $this->assertSame($alasan, $d->reason);
    }

    public function test_kirim_mengirim_html_berisi_kredensial_dan_caption_tanpa_password(): void
    {
        $d = $this->siapKirim('Sandi-Unik-789');
        Http::fake(['127.0.0.1:9/*' => Http::response(['ok' => true, 'id' => 'X', 'png_bytes' => 1], 200)]);

        app(VoucherWaService::class)->kirim($d->id);

        Http::assertSent(fn ($r) => str_contains($r['html'], 'zztest')
            && str_contains($r['html'], 'Sandi-Unik-789')
            && str_contains($r['caption'], 'zztest')
            && ! str_contains($r['caption'], 'Sandi-Unik-789')
            && $r['number'] === '6281234567890'
            && $r['viewport'] === ['width' => 402, 'scale' => 2]);
    }

    public function test_caption_template_diedit_berisi_placeholder_password_tidak_membocorkan(): void
    {
        $d = $this->siapKirim('Sandi-Unik-789');
        \App\Services\MessageTemplateService::save('voucher_baru', 'P={password} U={username}');
        Http::fake(['127.0.0.1:9/*' => Http::response(['ok' => true, 'id' => 'X', 'png_bytes' => 1], 200)]);

        app(VoucherWaService::class)->kirim($d->id);

        Http::assertSent(fn ($r) => $r['caption'] === 'P={password} U=zztest');
    }

    public function test_kirim_hanya_untuk_baris_antre(): void
    {
        $this->userHotspot();
        $a = WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281234567890', 'status' => WaVoucherDelivery::TERKIRIM]);
        $b = WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281234567890', 'status' => WaVoucherDelivery::DIBATALKAN]);
        Http::fake();

        app(VoucherWaService::class)->kirim($a->id);
        app(VoucherWaService::class)->kirim($b->id);
        app(VoucherWaService::class)->kirim(999999);

        Http::assertNothingSent();
        $this->assertSame(WaVoucherDelivery::TERKIRIM, $a->fresh()->status);
        $this->assertSame(WaVoucherDelivery::DIBATALKAN, $b->fresh()->status);
    }

    public function test_kirim_user_sudah_dihapus_jadi_gagal(): void
    {
        $d = WaVoucherDelivery::create(['username' => 'hilang', 'phone' => '6281234567890', 'status' => WaVoucherDelivery::ANTRE]);
        Http::fake();

        app(VoucherWaService::class)->kirim($d->id);

        Http::assertNothingSent();
        $this->assertSame(WaVoucherDelivery::GAGAL, $d->fresh()->status);
        $this->assertSame('User sudah dihapus', $d->fresh()->reason);
    }

    public function test_alasan_dipotong_bila_galat_gateway_panjang(): void
    {
        $d = $this->siapKirim();
        Http::fake(['127.0.0.1:9/*' => Http::response(['ok' => false, 'error' => str_repeat('x', 2000), 'code' => 'render_failed'], 500)]);

        app(VoucherWaService::class)->kirim($d->id);

        $reason = $d->fresh()->reason;
        $this->assertLessThanOrEqual(255, mb_strlen($reason));
        $this->assertStringStartsWith('Gambar voucher gagal dibuat (', $reason);
    }

    public function test_exception_sesudah_jawaban_terkirim_tidak_jadi_gagal(): void
    {
        $d = $this->siapKirim();
        Http::fake(['127.0.0.1:9/*' => Http::response(['ok' => true, 'id' => 'X', 'png_bytes' => 1], 200)]);
        ActivityLog::creating(fn () => throw new \RuntimeException('log rusak'));

        app(VoucherWaService::class)->kirim($d->id);

        $d->refresh();
        $this->assertSame(WaVoucherDelivery::TERKIRIM, $d->status);
        $this->assertSame('X', $d->message_id);
    }

    public function test_kunci_baris_tidak_ditahan_selama_panggilan_gateway(): void
    {
        $d = $this->siapKirim();
        $levelAwal = DB::transactionLevel();
        $levelSaat = null;
        $statusSaat = null;
        Http::fake(function () use ($d, &$levelSaat, &$statusSaat) {
            $levelSaat = DB::transactionLevel();
            $statusSaat = WaVoucherDelivery::find($d->id)->status;

            return Http::response(['ok' => true, 'id' => 'X', 'png_bytes' => 1], 200);
        });

        app(VoucherWaService::class)->kirim($d->id);

        $this->assertSame($levelAwal, $levelSaat);
        $this->assertSame(WaVoucherDelivery::DIPROSES, $statusSaat);
    }

    public function test_kirim_ulang_saat_mode_tes_ke_nomor_tes(): void
    {
        config(['services.billing.wa_test_mode' => true]);
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');
        WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281200099999', 'status' => WaVoucherDelivery::GAGAL]);

        $baru = app(VoucherWaService::class)->kirimUlang('zztest', null);

        $this->assertSame(WaVoucherDelivery::ANTRE, $baru->status);
        $this->assertSame('6281200099999', $baru->phone);
    }

    public function test_kirim_ulang_saat_notifikasi_mati_gagal_tanpa_job(): void
    {
        Queue::fake();
        config(['services.billing.wa_notifications' => false]);
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');
        WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281111111111', 'status' => WaVoucherDelivery::GAGAL]);

        $baru = app(VoucherWaService::class)->kirimUlang('zztest', null);

        $this->assertSame(WaVoucherDelivery::GAGAL, $baru->status);
        $this->assertSame('Notifikasi WA sedang dimatikan', $baru->reason);
        Queue::assertNothingPushed();
    }

    public function test_kirim_ulang_ditolak_saat_masih_aktif(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');
        WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281111111111', 'status' => WaVoucherDelivery::ANTRE]);

        try {
            app(VoucherWaService::class)->kirimUlang('zztest', null);
            $this->fail('Harusnya ditolak');
        } catch (VoucherMasihDikirim $e) {
            $this->assertSame('Voucher masih dalam proses kirim', $e->getMessage());
        }

        $this->assertSame(1, WaVoucherDelivery::where('username', 'zztest')->count());
    }

    public function test_kirim_ulang_membatalkan_baris_basi_dan_job_lama_tidak_mengirim(): void
    {
        $this->userHotspot();
        $this->kontak('zztest', '6281111111111');
        $lama = $this->kiriman(WaVoucherDelivery::ANTRE, 'zztest', '2026-10-09 09:40:00');
        Http::fake();

        $baru = app(VoucherWaService::class)->kirimUlang('zztest', null);
        app(VoucherWaService::class)->kirim($lama->id);

        $this->assertSame(WaVoucherDelivery::DIBATALKAN, $lama->fresh()->status);
        $this->assertSame(WaVoucherDelivery::ANTRE, $baru->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_tandai_belum_pasti_hanya_untuk_baris_aktif(): void
    {
        $a = $this->kiriman(WaVoucherDelivery::DIPROSES, 'a1');
        $b = $this->kiriman(WaVoucherDelivery::TERKIRIM, 'a2');
        $svc = app(VoucherWaService::class);

        $svc->tandaiBelumPasti($a->id, 'Proses kirim terhenti di tengah jalan');
        $svc->tandaiBelumPasti($b->id, 'Proses kirim terhenti di tengah jalan');

        $this->assertSame(WaVoucherDelivery::BELUM_PASTI, $a->fresh()->status);
        $this->assertSame('Proses kirim terhenti di tengah jalan', $a->fresh()->reason);
        $this->assertSame(WaVoucherDelivery::TERKIRIM, $b->fresh()->status);
    }

    public function test_activity_log_tanpa_password(): void
    {
        $d = $this->siapKirim('Sandi-Unik-789');
        Http::fake(['127.0.0.1:9/*' => Http::response(['ok' => true, 'id' => 'X', 'png_bytes' => 1], 200)]);

        app(VoucherWaService::class)->kirim($d->id);

        $log = ActivityLog::where('action', 'voucher_wa_kirim')->first();
        $this->assertNotNull($log);
        $this->assertSame('terkirim', $log->properties['status']);
        $this->assertStringNotContainsString('Sandi-Unik-789', json_encode(ActivityLog::all()->toArray()));
    }

    public function test_payload_job_tanpa_password(): void
    {
        $d = $this->siapKirim('Sandi-Unik-789');

        $job = new SendVoucherWhatsApp($d->id);

        $this->assertStringNotContainsString('Sandi-Unik-789', serialize($job));
        $this->assertSame(1, $job->tries);
        $this->assertSame(90, $job->timeout);
    }

    public function test_job_failed_menandai_belum_pasti(): void
    {
        $d = $this->kiriman(WaVoucherDelivery::DIPROSES);

        (new SendVoucherWhatsApp($d->id))->failed(new \RuntimeException('worker mati'));

        $this->assertSame(WaVoucherDelivery::BELUM_PASTI, $d->fresh()->status);
        $this->assertSame('Proses kirim terhenti di tengah jalan', $d->fresh()->reason);
    }

    public function test_job_menjalankan_service(): void
    {
        $d = $this->siapKirim();
        Http::fake(['127.0.0.1:9/*' => Http::response(['ok' => true, 'id' => 'J1', 'png_bytes' => 1], 200)]);

        SendVoucherWhatsApp::dispatchSync($d->id);

        $this->assertSame(WaVoucherDelivery::TERKIRIM, $d->fresh()->status);
        $this->assertSame('J1', $d->fresh()->message_id);
    }

    private function simpanBaru(?User $pelaku, array $isian)
    {
        $uji = $pelaku ? $this->actingAs($pelaku) : $this;

        return $uji->from(route('user-hotspot.create'))
            ->post(route('user-hotspot.store'), $isian + ['username' => 'zzbaru', 'password' => 'abc123', 'group' => '', 'expiry' => '', 'phone' => '081200000001']);
    }

    private function adaUser(string $username = 'zzbaru'): bool
    {
        return RadCheck::where('username', $username)->where('attribute', 'Cleartext-Password')->exists();
    }

    public function test_manual_lolos_tanpa_job_tanpa_request(): void
    {
        Queue::fake();
        Http::fake();
        $admin = $this->pengguna('admin');

        $this->simpanBaru($admin, ['kirim_voucher' => 'manual'])->assertRedirect(route('user-hotspot.index'));

        $this->assertSame('zzbaru', session('voucher_baru'));
        $this->assertTrue($this->adaUser());
        $this->assertSame(0, WaVoucherDelivery::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();

        $this->actingAs($admin)->get(route('user-hotspot.index'))
            ->assertOk()
            ->assertSee(route('user-hotspot.voucher', 'zzbaru'), false)
            ->assertSee('Buka voucher');
    }

    public function test_manual_dengan_nomor_menyimpan_kontak(): void
    {
        Queue::fake();
        $this->simpanBaru($this->pengguna('admin'), ['kirim_voucher' => 'manual', 'phone' => '081234567890'])->assertSessionHasNoErrors();

        $this->assertSame('6281234567890', CustomerContact::where('username', 'zzbaru')->value('phone'));
        $this->assertSame(0, WaVoucherDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_otomatis_tanpa_nomor_ditolak_user_tidak_dibuat(): void
    {
        Queue::fake();
        $this->simpanBaru($this->pengguna('admin'), ['kirim_voucher' => 'otomatis', 'phone' => ''])
            ->assertRedirect(route('user-hotspot.create'))
            ->assertSessionHasErrors(['phone' => 'Nomor WhatsApp wajib diisi.']);

        $this->assertFalse($this->adaUser());
        Queue::assertNothingPushed();
    }

    public function test_mode_tidak_dikenal_ditolak(): void
    {
        $this->simpanBaru($this->pengguna('admin'), ['kirim_voucher' => 'massal', 'phone' => '081234567890'])
            ->assertSessionHasErrors('kirim_voucher');

        $this->assertFalse($this->adaUser());
    }

    public function test_mode_hilang_dianggap_manual(): void
    {
        Queue::fake();
        $this->simpanBaru($this->pengguna('admin'), ['phone' => '081234567890'])->assertSessionHasNoErrors();

        $this->assertTrue($this->adaUser());
        $this->assertSame(0, WaVoucherDelivery::count());
        $this->assertSame('zzbaru', session('voucher_baru'));
        Queue::assertNothingPushed();
    }

    public function test_otomatis_membuat_satu_kiriman_dan_job(): void
    {
        Queue::fake();
        $this->simpanBaru($this->pengguna('operator'), ['kirim_voucher' => 'otomatis', 'phone' => '081234567890'])
            ->assertRedirect(route('user-hotspot.index'));

        $d = WaVoucherDelivery::sole();
        $this->assertSame(WaVoucherDelivery::ANTRE, $d->status);
        $this->assertSame('6281234567890', $d->phone);
        Queue::assertPushed(SendVoucherWhatsApp::class, fn ($j) => $j->deliveryId === $d->id
            && $j->tries === 1 && $j->timeout === 90 && $j->afterCommit === true);
        $this->assertStringContainsString('Voucher sedang dikirim ke 6281234567890', session('success'));
        $this->assertNull(session('voucher_baru'));
        $this->assertSame('6281234567890', CustomerContact::where('username', 'zzbaru')->value('phone'));
    }

    public function test_otomatis_saat_notifikasi_mati_user_dibuat_kiriman_gagal(): void
    {
        Queue::fake();
        config(['services.billing.wa_notifications' => false]);

        $this->simpanBaru($this->pengguna('admin'), ['kirim_voucher' => 'otomatis', 'phone' => '081234567890'])->assertSessionHasNoErrors();

        $this->assertTrue($this->adaUser());
        $this->assertSame(WaVoucherDelivery::GAGAL, WaVoucherDelivery::sole()->status);
        $this->assertStringContainsString('Voucher tidak dikirim: Notifikasi WA sedang dimatikan.', session('success'));
        Queue::assertNothingPushed();
    }

    public function test_exception_setelah_kiriman_dibuat_tidak_mengirim_apa_pun(): void
    {
        Http::fake();
        WaVoucherDelivery::created(fn () => throw new \RuntimeException('gagal sesudah kiriman dibuat'));

        $this->simpanBaru($this->pengguna('admin'), ['kirim_voucher' => 'otomatis', 'phone' => '081234567890'])->assertStatus(500);

        Http::assertNothingSent();
        $this->assertFalse($this->adaUser());
        $this->assertSame(0, WaVoucherDelivery::count());
        $this->assertNull(CustomerContact::where('username', 'zzbaru')->value('phone'));
    }

    public function test_username_sama_dikirim_dua_kali_hanya_satu_user_satu_kiriman(): void
    {
        Queue::fake();
        $admin = $this->pengguna('admin');

        $this->simpanBaru($admin, ['kirim_voucher' => 'otomatis', 'phone' => '081234567890'])->assertSessionHasNoErrors();
        $this->simpanBaru($admin, ['kirim_voucher' => 'otomatis', 'phone' => '081234567890'])->assertSessionHasErrors('username');

        $this->assertSame(1, RadCheck::where('username', 'zzbaru')->where('attribute', 'Cleartext-Password')->count());
        $this->assertSame(1, WaVoucherDelivery::count());
        Queue::assertPushed(SendVoucherWhatsApp::class, 1);
    }

    public function test_kunci_dipegang_proses_lain_galat_username(): void
    {
        Queue::fake();
        Cache::lock('user-hotspot:zzbaru', 10)->get();

        $this->simpanBaru($this->pengguna('admin'), ['kirim_voucher' => 'otomatis', 'phone' => '081234567890'])
            ->assertSessionHasErrors(['username' => 'Username sedang diproses, coba lagi sebentar.']);

        $this->assertFalse($this->adaUser());
        $this->assertSame(0, WaVoucherDelivery::count());
    }

    public function test_daftar_user_berisi_data_kiriman_terakhir(): void
    {
        $this->userHotspot('zztest');
        $this->userHotspot('zzlain');
        WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281234567890', 'status' => WaVoucherDelivery::GAGAL, 'reason' => 'lama']);
        WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281234567890', 'status' => WaVoucherDelivery::TERKIRIM, 'sent_at' => now()]);

        $html = $this->actingAs($this->pengguna('admin'))->get(route('user-hotspot.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-kirim-status="terkirim"'));
        $this->assertStringContainsString('data-kirim-ulang="0"', $html);
        $this->assertMatchesRegularExpression('/data-kirim-label="Terkirim [^"]*ke 6281234567890"/', $html);
        $this->assertStringNotContainsString('Gagal: lama', $html);
        $this->assertSame(1, substr_count($html, 'data-kirim-status=""'));
        $this->assertStringContainsString('id="dr-kirim-form"', $html);
    }

    public static function labelPerStatus(): array
    {
        return [
            'terkirim'      => [WaVoucherDelivery::TERKIRIM, null, 'terkirim', 'Terkirim ', false, false],
            'gagal'         => [WaVoucherDelivery::GAGAL, null, 'gagal', 'Gagal: Nomor ini tidak terdaftar di WhatsApp', true, false],
            'belum pasti'   => [WaVoucherDelivery::BELUM_PASTI, null, 'belum_pasti', 'Belum pasti: Hasil kiriman tidak pasti', true, true],
            'antre segar'   => [WaVoucherDelivery::ANTRE, null, 'antre', 'Sedang dikirim ke 6281234567890', false, false],
            'diproses basi' => [WaVoucherDelivery::DIPROSES, '2026-10-09 09:50:00', 'belum_pasti', 'Belum pasti: proses kirim tidak selesai', true, true],
        ];
    }

    #[DataProvider('labelPerStatus')]
    public function test_label_drawer_per_status(string $status, ?string $diubah, string $harapStatus, string $awalan, bool $ulang, bool $peringatan): void
    {
        $d = $this->kiriman($status, 'zztest', $diubah);
        $alasan = [WaVoucherDelivery::GAGAL => 'Nomor ini tidak terdaftar di WhatsApp', WaVoucherDelivery::BELUM_PASTI => 'Hasil kiriman tidak pasti; pesan mungkin sudah sampai'][$status] ?? null;
        if ($alasan !== null) {
            WaVoucherDelivery::whereKey($d->id)->update(['reason' => $alasan]);
            $d->refresh();
        }
        if ($status === WaVoucherDelivery::TERKIRIM) {
            WaVoucherDelivery::whereKey($d->id)->update(['sent_at' => now()]);
            $d->refresh();
        }

        $label = app(VoucherWaService::class)->labelDrawer($d);

        $this->assertSame($harapStatus, $label['status']);
        $this->assertStringStartsWith($awalan, $label['label']);
        $this->assertSame($ulang, $label['ulang']);
        $this->assertSame($peringatan, $label['peringatan']);
    }

    public static function peranPanel(): array
    {
        return [['admin'], ['operator']];
    }

    #[DataProvider('peranPanel')]
    public function test_kirim_ulang_admin_dan_operator(string $role): void
    {
        Queue::fake();
        $this->userHotspot();
        $this->kontak('zztest', '6281234567890');
        WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281234567890', 'status' => WaVoucherDelivery::GAGAL]);

        $this->actingAs($this->pengguna($role))
            ->from(route('user-hotspot.index'))
            ->post(route('user-hotspot.kirim-voucher', 'zztest'))
            ->assertRedirect(route('user-hotspot.index'));

        $baru = WaVoucherDelivery::latest('id')->first();
        $this->assertSame(WaVoucherDelivery::ANTRE, $baru->status);
        $this->assertStringContainsString('dikirim ulang ke 6281234567890', session('success'));
        Queue::assertPushed(SendVoucherWhatsApp::class, fn ($j) => $j->deliveryId === $baru->id && $j->afterCommit === true);
    }

    public function test_kirim_ulang_tamu_ditolak(): void
    {
        Queue::fake();
        $this->userHotspot();
        WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281234567890', 'status' => WaVoucherDelivery::GAGAL]);

        $this->post(route('user-hotspot.kirim-voucher', 'zztest'))->assertRedirect(route('login'));

        $this->assertSame(1, WaVoucherDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_kirim_ulang_ditolak_saat_masih_aktif_lewat_http(): void
    {
        Queue::fake();
        $this->userHotspot();
        $this->kontak('zztest', '6281234567890');
        WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281234567890', 'status' => WaVoucherDelivery::DIPROSES]);

        $this->actingAs($this->pengguna('admin'))
            ->from(route('user-hotspot.index'))
            ->post(route('user-hotspot.kirim-voucher', 'zztest'))
            ->assertRedirect(route('user-hotspot.index'));

        $this->assertSame('Voucher masih dalam proses kirim', session('error'));
        $this->assertSame(1, WaVoucherDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_kirim_ulang_user_tidak_ada_404(): void
    {
        $this->actingAs($this->pengguna('admin'))
            ->post(route('user-hotspot.kirim-voucher', 'tidakada'))
            ->assertNotFound();
    }

    private function siapRoot(): void
    {
        $this->userHotspot('root', 'sandi-root');
        $this->kontak('root', '6281200099999');
    }

    public function test_command_menolak_selain_root(): void
    {
        $this->userHotspot('zztest');
        Http::fake();

        $this->artisan('voucher-wa:uji', ['username' => 'zztest', '--jalan' => true])
            ->expectsOutputToContain('Hanya akun root')
            ->assertExitCode(1);

        $this->assertSame(0, WaVoucherDelivery::count());
        Http::assertNothingSent();
    }

    public function test_command_tanpa_jalan_tidak_membuat_kiriman(): void
    {
        $this->siapRoot();
        Http::fake();

        $this->artisan('voucher-wa:uji')
            ->expectsOutputToContain('6281200099999')
            ->expectsOutputToContain('Tidak ada yang dikirim')
            ->assertExitCode(0);

        $this->assertSame(0, WaVoucherDelivery::count());
        Http::assertNothingSent();
    }

    public function test_command_jalan_mengirim_dan_mencetak_status(): void
    {
        $this->siapRoot();
        Http::fake(['127.0.0.1:9/*' => Http::response(['ok' => true, 'id' => 'C1', 'png_bytes' => 1], 200)]);

        $this->artisan('voucher-wa:uji', ['--jalan' => true])
            ->expectsOutputToContain('terkirim')
            ->assertExitCode(0);

        $this->assertSame(WaVoucherDelivery::TERKIRIM, WaVoucherDelivery::sole()->status);
    }

    public function test_command_jalan_keluar_1_bila_tidak_terkirim(): void
    {
        $this->siapRoot();
        Http::fake(['127.0.0.1:9/*' => Http::response(['ok' => false, 'error' => 'x', 'code' => 'send_failed'], 500)]);

        $this->artisan('voucher-wa:uji', ['--jalan' => true])
            ->expectsOutputToContain('belum_pasti')
            ->assertExitCode(1);
    }

    public function test_command_antrean_sukses_lewat_job(): void
    {
        $this->siapRoot();
        Sleep::fake();
        Http::fake(['127.0.0.1:9/*' => Http::response(['ok' => true, 'id' => 'Q1', 'png_bytes' => 1], 200)]);

        $this->artisan('voucher-wa:uji', ['--jalan' => true, '--antrean' => true])
            ->expectsOutputToContain('terkirim')
            ->assertExitCode(0);

        $this->assertSame('Q1', WaVoucherDelivery::sole()->message_id);
    }

    public function test_command_antrean_gagal_bila_baris_tidak_diproses(): void
    {
        $this->siapRoot();
        Queue::fake();
        Sleep::fake();
        Http::fake();

        $this->artisan('voucher-wa:uji', ['--jalan' => true, '--antrean' => true, '--tunggu' => 4])
            ->expectsOutputToContain('worker antrean tidak memproses kiriman')
            ->assertExitCode(1);

        Queue::assertPushed(SendVoucherWhatsApp::class, 1);
        Http::assertNothingSent();
    }

    public function test_galat_kunci_tidak_menyimpan_password_di_sesi(): void
    {
        Queue::fake();
        Cache::lock('user-hotspot:zzbaru', 10)->get();

        $this->simpanBaru($this->pengguna('admin'), ['kirim_voucher' => 'manual', 'password' => 'Sandi-Rahasia-55'])
            ->assertSessionHasErrors('username');

        $this->assertNull(session()->getOldInput('password'));
        $this->assertSame('zzbaru', session()->getOldInput('username'));
    }

    public function test_kirim_ulang_ditolak_bila_sudah_terkirim(): void
    {
        Queue::fake();
        $this->userHotspot();
        $this->kontak('zztest', '6281234567890');
        WaVoucherDelivery::create(['username' => 'zztest', 'phone' => '6281234567890', 'status' => WaVoucherDelivery::TERKIRIM, 'sent_at' => now()]);

        $this->actingAs($this->pengguna('admin'))
            ->from(route('user-hotspot.index'))
            ->post(route('user-hotspot.kirim-voucher', 'zztest'))
            ->assertRedirect(route('user-hotspot.index'));

        $this->assertSame('Voucher sudah terkirim, tidak perlu dikirim ulang', session('error'));
        $this->assertSame(1, WaVoucherDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_kirim_ulang_ditolak_bila_belum_pernah_dikirim_lewat_panel(): void
    {
        Queue::fake();
        $this->userHotspot();
        $this->kontak('zztest', '6281234567890');

        $this->actingAs($this->pengguna('admin'))
            ->from(route('user-hotspot.index'))
            ->post(route('user-hotspot.kirim-voucher', 'zztest'))
            ->assertRedirect(route('user-hotspot.index'));

        $this->assertSame('User ini belum pernah dikirimi voucher lewat panel', session('error'));
        $this->assertSame(0, WaVoucherDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_command_jalan_ditolak_bila_tujuan_bukan_nomor_uji(): void
    {
        $this->userHotspot('root', 'sandi-root');
        $this->kontak('root', '6281299999999');
        Http::fake();

        $this->artisan('voucher-wa:uji', ['--jalan' => true])
            ->expectsOutputToContain('hanya boleh ke 6281200099999')
            ->assertExitCode(1);

        $this->assertSame(0, WaVoucherDelivery::count());
        Http::assertNothingSent();
    }

    public function test_tombol_simpan_dipulihkan_saat_kembali_dari_bfcache(): void
    {
        $this->actingAs($this->pengguna('admin'))
            ->get(route('user-hotspot.create'))
            ->assertOk()
            ->assertSee('@pageshow.window="mengirim = false"', false);
    }
}
