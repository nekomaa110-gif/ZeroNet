<?php

namespace Tests\Feature;

use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherImportService;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherNomorTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    private Router $router;

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

    private function mulai(array $users = []): void
    {
        $this->palsu = RouterPalsu::mulai($users, [], [
            '/ip/hotspot/user/profile' => [
                ['name' => '4-JAM', 'on-login' => ':put (",ntfc,5000,1d,5000,,Disable,"); {}'],
                ['name' => '5-JAM', 'on-login' => ':put (",ntfc,5000,1d,0,,Disable,")'],
            ],
        ]);

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->actingAs(User::create([
            'name' => 'Admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('sandi-admin'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));
    }

    private static function userImpor(string $nama, string $comment): array
    {
        return ['name' => $nama, 'password' => 'p' . $nama, 'profile' => '5-JAM', 'comment' => $comment, 'uptime' => '0s'];
    }

    private function batchLama(string $code): VoucherBatch
    {
        return VoucherBatch::create([
            'router_id' => $this->router->id, 'router_name' => 'UJI', 'code' => $code,
            'profile' => '5-JAM', 'quantity' => 0, 'status' => 'success', 'source' => 'mikhmon_import',
        ]);
    }

    private function kartuLama(VoucherBatch $batch, string $nama, ?int $nomor): void
    {
        Voucher::create([
            'batch_id' => $batch->id, 'router_id' => $this->router->id, 'nomor' => $nomor, 'username' => $nama, 'password' => 'x',
            'profile' => '5-JAM', 'comment' => 'up-010-a', 'status' => 'ready', 'sync_status' => 'success',
        ]);
    }

    private function riwayatLama(VoucherBatch $batch, int $voucherId, ?int $nomor): void
    {
        DeletedVoucher::create([
            'voucher_id' => $voucherId, 'batch_id' => $batch->id, 'batch_code' => $batch->code, 'router_id' => $this->router->id,
            'nomor' => $nomor, 'username' => "hapus{$voucherId}", 'password' => 'x', 'status' => 'expired',
            'hasil_router' => 'tidak_dicabut', 'cara' => 'massal', 'sumber' => 'panel', 'deleted_at' => now(),
        ]);
    }

    private function impor(): void
    {
        app(VoucherImportService::class)->impor($this->router);
    }

    private function nomor(array $nama): array
    {
        return Voucher::whereIn('username', $nama)->orderBy('id')->pluck('nomor')->all();
    }

    private function assertNomorUnikPerBatch(): void
    {
        $ganda = DB::query()
            ->fromSub(
                DB::table('vouchers')->select('batch_id', 'nomor')->whereNotNull('nomor')
                    ->unionAll(DB::table('deleted_vouchers')->select('batch_id', 'nomor')->whereNotNull('nomor')),
                'g'
            )
            ->select('batch_id', 'nomor')
            ->groupBy('batch_id', 'nomor')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(0, $ganda, 'Ada (batch_id, nomor) ganda: ' . $ganda->toJson());
    }

    public function test_generate_memberi_nomor_1_sampai_n(): void
    {
        $this->mulai();
        Bus::fake();

        $batch = app(VoucherService::class)->generate($this->router, ['quantity' => 5, 'profile' => '4-JAM', 'charset' => 'num', 'code_length' => 6, 'mode' => 'vc'], null);

        $this->assertSame([1, 2, 3, 4, 5], $batch->vouchers()->orderBy('id')->pluck('nomor')->all());
        $this->assertNomorUnikPerBatch();
    }

    public function test_generate_lintas_potongan_insert_tetap_berurutan(): void
    {
        $this->mulai();
        Bus::fake();

        $batch = app(VoucherService::class)->generate($this->router, ['quantity' => 250, 'profile' => '4-JAM', 'charset' => 'num', 'code_length' => 6, 'mode' => 'vc'], null);

        $this->assertSame(range(1, 250), $batch->vouchers()->orderBy('id')->pluck('nomor')->all());
        $this->assertNomorUnikPerBatch();
    }

    public function test_kartu_cepat_bernomor_1(): void
    {
        $this->mulai();

        $this->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => '4-JAM', 'username' => 'tamu1'])->assertOk();

        $this->assertSame(1, Voucher::where('username', 'tamu1')->value('nomor'));
        $this->assertNomorUnikPerBatch();
    }

    public function test_impor_batch_baru_bernomor_sesuai_urutan_router(): void
    {
        $this->mulai([self::userImpor('m3', 'up-010-a'), self::userImpor('m1', 'up-010-a'), self::userImpor('m2', 'up-010-a')]);

        $this->impor();

        $this->assertSame(['m3' => 1, 'm1' => 2, 'm2' => 3], Voucher::orderBy('nomor')->pluck('nomor', 'username')->all());
        $this->assertNomorUnikPerBatch();
    }

    public function test_impor_ke_batch_lama_melanjutkan_nomor_tertinggi_gabungan(): void
    {
        $this->mulai([self::userImpor('baru1', 'up-010-a'), self::userImpor('baru2', 'up-010-a')]);
        $batch = $this->batchLama('up-010-a@uji');
        $this->kartuLama($batch, 'lama1', 1);
        $this->kartuLama($batch, 'lama2', 2);
        $this->riwayatLama($batch, 99001, 3);

        $this->impor();

        $this->assertSame([4, 5], $this->nomor(['baru1', 'baru2']));
        $this->assertNomorUnikPerBatch();
    }

    public function test_impor_ke_batch_lama_yang_belum_bernomor_tetap_tanpa_nomor(): void
    {
        $this->mulai([self::userImpor('baru1', 'up-010-a')]);
        $batch = $this->batchLama('up-010-a@uji');
        $this->kartuLama($batch, 'lama1', null);

        $this->impor();

        $this->assertSame([null], $this->nomor(['baru1']));
        $this->assertNomorUnikPerBatch();
    }

    public function test_impor_ke_batch_campuran_bernomor_dan_tanpa_nomor_tetap_tanpa_nomor(): void
    {
        $this->mulai([self::userImpor('baru1', 'up-010-a')]);
        $batch = $this->batchLama('up-010-a@uji');
        $this->kartuLama($batch, 'lama1', null);
        $this->kartuLama($batch, 'lama2', 2);
        $this->riwayatLama($batch, 99001, 3);

        $this->impor();

        $this->assertSame([null], $this->nomor(['baru1']));
        $this->assertNomorUnikPerBatch();
    }

    public function test_impor_ke_batch_bernomor_dengan_riwayat_tanpa_nomor_tetap_tanpa_nomor(): void
    {
        $this->mulai([self::userImpor('baru1', 'up-010-a')]);
        $batch = $this->batchLama('up-010-a@uji');
        $this->kartuLama($batch, 'lama1', 1);
        $this->kartuLama($batch, 'lama2', 2);
        $this->riwayatLama($batch, 99001, null);

        $this->impor();

        $this->assertSame([null], $this->nomor(['baru1']));
        $this->assertNomorUnikPerBatch();
    }

    public function test_impor_dua_batch_dan_lebih_dari_500_kartu_bernomor_per_batch(): void
    {
        $users = [self::userImpor('a1', 'up-020-a')];
        for ($i = 1; $i <= 250; $i++) {
            $users[] = self::userImpor("b{$i}", 'up-021-b');
        }
        $users[] = self::userImpor('a2', 'up-020-a');
        for ($i = 251; $i <= 502; $i++) {
            $users[] = self::userImpor("b{$i}", 'up-021-b');
        }
        $users[] = self::userImpor('a3', 'up-020-a');
        $this->mulai($users);

        $this->impor();

        $a = VoucherBatch::where('code', 'up-020-a@uji')->sole();
        $b = VoucherBatch::where('code', 'up-021-b@uji')->sole();
        $this->assertSame(['a1' => 1, 'a2' => 2, 'a3' => 3], Voucher::where('batch_id', $a->id)->orderBy('nomor')->pluck('nomor', 'username')->all());
        $this->assertSame(range(1, 502), Voucher::where('batch_id', $b->id)->orderBy('id')->pluck('nomor')->all());
        $this->assertSame(array_map(fn ($i) => "b{$i}", range(1, 502)), Voucher::where('batch_id', $b->id)->orderBy('nomor')->pluck('username')->all());
        $this->assertNomorUnikPerBatch();
    }

    public function test_impor_ke_batch_yang_hanya_tersisa_riwayat_melanjutkan_nomor_riwayat(): void
    {
        $this->mulai([self::userImpor('baru1', 'up-010-a'), self::userImpor('baru2', 'up-010-a')]);
        $batch = $this->batchLama('up-010-a@uji');
        foreach ([1, 2, 3] as $n) {
            $this->riwayatLama($batch, 99000 + $n, $n);
        }

        $this->impor();

        $this->assertSame([4, 5], $this->nomor(['baru1', 'baru2']));
        $this->assertNomorUnikPerBatch();
    }

    public function test_impor_ke_batch_dengan_riwayat_tanpa_nomor_tetap_tanpa_nomor(): void
    {
        $this->mulai([self::userImpor('baru1', 'up-010-a'), self::userImpor('baru2', 'up-010-a')]);
        $batch = $this->batchLama('up-010-a@uji');
        $this->riwayatLama($batch, 99001, null);

        $this->impor();

        $this->assertSame([null, null], $this->nomor(['baru1', 'baru2']));
        $this->assertNomorUnikPerBatch();
    }

    public function test_impor_ke_batch_lama_yang_kosong_tanpa_riwayat_tetap_tanpa_nomor(): void
    {
        $this->mulai([self::userImpor('baru1', 'up-010-a'), self::userImpor('baru2', 'up-010-a')]);
        $this->batchLama('up-010-a@uji');

        $this->impor();

        $this->assertSame([null, null], $this->nomor(['baru1', 'baru2']));
        $this->assertNomorUnikPerBatch();
    }
}
