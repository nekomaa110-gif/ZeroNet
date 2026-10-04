<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherSalesReport;
use App\Services\VoucherSalesService;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class StatusVoucherTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    private Router $router;

    private VoucherBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->palsu?->berhenti();
        parent::tearDown();
    }

    private function siapkan(array $users = [], array $record = []): void
    {
        $this->palsu = RouterPalsu::mulai($users, [], [
            '/system/script'           => array_map(fn ($n) => ['name' => $n, 'owner' => 'x', 'comment' => 'mikhmon'], $record),
            '/ip/hotspot/user/profile' => [['name' => '5-JAM', 'on-login' => ':put (",ntfc,5000,1d,0,,Disable,")']],
        ]);

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->batch = VoucherBatch::create([
            'router_id' => $this->router->id, 'router_name' => 'UJI', 'code' => 'up-001-uji',
            'profile' => '5-JAM', 'quantity' => 1, 'status' => 'success', 'validity' => '1d',
        ]);
    }

    private function voucher(string $nama, array $ubah = []): Voucher
    {
        return Voucher::create($ubah + [
            'batch_id' => $this->batch->id, 'router_id' => $this->router->id, 'username' => $nama, 'password' => $nama,
            'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success',
        ]);
    }

    private static function record(Carbon $waktu, string $user): string
    {
        return strtolower($waktu->format('M/d/Y')) . "-|-{$waktu->format('H:i:s')}-|-{$user}-|-5000-|-10.10.10.9-|-AA:BB:CC:DD:EE:01-|-1d-|-5-JAM-|-up-001-uji";
    }

    private static function stempel(Carbon $waktu): string
    {
        return strtolower($waktu->format('M/d/Y H:i:s'));
    }

    public function test_record_penjualan_baru_menandai_voucher_belum_dipakai_tanpa_tertipu_nama_kembar_lama(): void
    {
        $jual = now()->subMinutes(2)->startOfSecond();
        $this->siapkan([], [self::record($jual, 'segar'), self::record(now()->subDays(3), 'kembar')]);

        $this->voucher('segar', ['checked_at' => now()->subMinutes(10)]);
        $this->voucher('kembar', ['checked_at' => now()->subMinutes(10)]);
        $this->voucher('utuh', ['checked_at' => now()->subMinutes(10)]);

        app(VoucherSalesService::class)->tarik($this->router->fresh());

        $segar = Voucher::where('username', 'segar')->first();
        $this->assertSame('active', $segar->status);
        $this->assertTrue($segar->activated_at->equalTo($jual));
        $this->assertSame('ready', Voucher::where('username', 'kembar')->value('status'));
        $this->assertSame('ready', Voucher::where('username', 'utuh')->value('status'));

        $this->assertEquals(['ready' => 2, 'active' => 1], app(VoucherSalesReport::class)->ringkasan()['voucher'][$this->router->id]);
    }

    public function test_habis_baru_terhitung_setelah_satu_siklus_pengawas_dan_voucher_gagal_masuk_router_tidak_dihitung(): void
    {
        $this->siapkan();
        $this->voucher('baru-lewat', ['status' => 'active', 'expired_at' => now()->subMinute()]);
        $this->voucher('lewat', ['status' => 'active', 'expired_at' => now()->subMinutes(10)]);
        $this->voucher('masih', ['status' => 'active', 'expired_at' => now()->addHour()]);
        $this->voucher('siap');
        $this->voucher('gagal-push', ['sync_status' => 'failed']);

        $this->assertSame(240, Voucher::jedaPengawas());
        $this->assertSame('active', Voucher::where('username', 'baru-lewat')->first()->status_efektif);
        $this->assertSame('expired', Voucher::where('username', 'lewat')->first()->status_efektif);
        $this->assertSame(['lewat'], Voucher::query()->status('expired')->pluck('username')->all());

        $this->assertEquals(['ready' => 1, 'active' => 2, 'expired' => 1], app(VoucherSalesReport::class)->ringkasan()['voucher'][$this->router->id]);

        $stats = app(VoucherService::class)->stats();
        $this->assertSame([5, 1, 2, 1, 3, 1], [$stats['total'], $stats['ready'], $stats['active'], $stats['expired'], $stats['terjual'], $stats['gagal']]);
    }

    public function test_sinkron_stempel_baru_lewat_tetap_aktif_sampai_pengawas_memutus_dan_jatah_jam_habis_masuk_habis(): void
    {
        $this->siapkan([
            ['name' => 'x1', 'password' => 'x1', 'profile' => '5-JAM', 'comment' => self::stempel(now()->subMinute()), 'uptime' => '1h', 'limit-uptime' => '5h'],
            ['name' => 'x2', 'password' => 'x2', 'profile' => '5-JAM', 'comment' => self::stempel(now()->subMinutes(10)), 'uptime' => '2h', 'limit-uptime' => '5h'],
            ['name' => 'x3', 'password' => 'x3', 'profile' => '5-JAM', 'comment' => self::stempel(now()->subMinute()), 'uptime' => '2h', 'limit-uptime' => '1s'],
            ['name' => 'x4', 'password' => 'x4', 'profile' => '5-JAM', 'comment' => 'up-001-uji', 'uptime' => '0s', 'limit-uptime' => '5h'],
            ['name' => 'x5', 'password' => 'x5', 'profile' => '5-JAM', 'comment' => self::stempel(now()->addHours(6)), 'uptime' => '5h1s', 'limit-uptime' => '5h'],
            ['name' => 'x6', 'password' => 'x6', 'profile' => '5-JAM', 'comment' => self::stempel(now()->addHours(6)), 'uptime' => '4h59m59s', 'limit-uptime' => '5h'],
            ['name' => 'x7', 'password' => 'x7', 'profile' => '5-JAM', 'comment' => self::stempel(now()->addHours(6)), 'uptime' => '9h', 'limit-uptime' => ''],
        ]);
        foreach (['x1', 'x2', 'x3', 'x4', 'x5', 'x6', 'x7'] as $n) {
            $this->voucher($n);
        }

        app(VoucherService::class)->reconcile($this->router->fresh());

        $this->assertSame(
            ['x1' => 'active', 'x2' => 'expired', 'x3' => 'expired', 'x4' => 'ready', 'x5' => 'expired', 'x6' => 'active', 'x7' => 'active'],
            Voucher::orderBy('username')->pluck('status', 'username')->all(),
        );
    }

    public function test_tabel_voucher_di_dashboard_tanpa_kolom_aktif(): void
    {
        $this->siapkan();
        $this->voucher('siap');
        $this->actingAs(User::create([
            'name' => 'admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('x'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['<th class="ta-r">Belum dipakai</th>', '<th class="ta-r">Habis</th>'], false)
            ->assertDontSee('<th class="ta-r">Aktif</th>', false);
    }
}
