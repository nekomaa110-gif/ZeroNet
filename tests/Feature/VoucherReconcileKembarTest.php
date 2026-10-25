<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherReconcileKembarTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    private Router $router;

    private VoucherBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->palsu?->berhenti();
        parent::tearDown();
    }

    private function siapkan(array $users, array $skenario = []): void
    {
        $this->palsu = RouterPalsu::mulai($users, $skenario);

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->batch = VoucherBatch::create([
            'router_id' => $this->router->id, 'router_name' => 'UJI', 'code' => 'up-001-uji',
            'profile' => '5-JAM', 'quantity' => 1, 'status' => 'success', 'validity' => '1d',
        ]);
    }

    private function kartu(string $nama, string $password = 'p'): Voucher
    {
        return Voucher::create([
            'batch_id' => $this->batch->id, 'router_id' => $this->router->id, 'username' => $nama, 'password' => $password,
            'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success',
        ]);
    }

    private static function baris(string $nama, ?string $password, string $limit): array
    {
        $baris = ['name' => $nama, 'profile' => '5-JAM', 'comment' => 'up-001-uji', 'uptime' => '0s', 'limit-uptime' => $limit];

        return $password === null ? $baris : $baris + ['password' => $password];
    }

    private function sinkron(): array
    {
        return app(VoucherService::class)->reconcile($this->router->fresh());
    }

    public function test_kembar_memakai_baris_yang_passwordnya_cocok(): void
    {
        $this->siapkan([self::baris('k1', 'p', '5h'), self::baris('k1', 'lain', '1s')]);
        $kartu = $this->kartu('k1');

        $this->sinkron();

        $this->assertSame('ready', $kartu->fresh()->status);
    }

    public function test_nama_ada_tapi_password_beda_jadi_missing(): void
    {
        $this->siapkan([self::baris('k2', 'lain', '5h')]);
        $kartu = $this->kartu('k2');

        $this->sinkron();

        $this->assertSame('missing', $kartu->fresh()->status);
    }

    public function test_satu_baris_tanpa_field_password_memakai_perilaku_lama(): void
    {
        $this->siapkan([self::baris('k3', null, '1s')], ['sembunyikan_password' => true]);
        $kartu = $this->kartu('k3');

        $this->sinkron();

        $this->assertSame('expired', $kartu->fresh()->status);
    }

    public function test_kembar_tanpa_info_password_tidak_mengubah_status(): void
    {
        $this->siapkan([self::baris('k4', 'p', '5h'), self::baris('k4', 'lain', '1s')], ['sembunyikan_password' => true]);
        $kartu = $this->kartu('k4');

        $angka = $this->sinkron();

        $this->assertSame('ready', $kartu->fresh()->status);
        $this->assertNull($kartu->fresh()->checked_at);
        $this->assertSame(0, $angka['diperiksa']);
    }

    public function test_beda_huruf_tidak_dipakai(): void
    {
        $this->siapkan([self::baris('K5', 'p', '5h')]);
        $kartu = $this->kartu('k5');

        $this->sinkron();

        $this->assertSame('missing', $kartu->fresh()->status);
    }

    public function test_kartu_berhuruf_besar_dan_kartu_angka_tetap_dikenali(): void
    {
        $this->siapkan([self::baris('0X49079', '4827', '5h'), self::baris('48213', '2345', '5h')]);
        $huruf = $this->kartu('0X49079', '4827');
        $angka = $this->kartu('48213', '2345');

        $this->sinkron();

        $this->assertSame('ready', $huruf->fresh()->status);
        $this->assertSame('ready', $angka->fresh()->status);
    }

    public function test_router_memberi_password_baris_tanpa_field_dianggap_beda(): void
    {
        $this->siapkan([self::baris('k6', null, '5h'), self::baris('k7', 'p', '5h')]);
        $k6 = $this->kartu('k6');
        $k7 = $this->kartu('k7');

        $this->sinkron();

        $this->assertSame('missing', $k6->fresh()->status);
        $this->assertSame('ready', $k7->fresh()->status);
    }
}
