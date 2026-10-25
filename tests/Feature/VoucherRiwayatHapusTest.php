<?php

namespace Tests\Feature;

use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\PencabutKartuService;
use App\Services\VoucherRouterService;
use App\Services\VoucherService;
use App\Services\VoucherTerhapusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherRiwayatHapusTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    private Router $router;

    private VoucherBatch $batch;

    private User $admin;

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

    private function mulai(array $users = [], array $skenario = []): void
    {
        $this->palsu = RouterPalsu::mulai($users, $skenario);

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->batch = VoucherBatch::create([
            'router_id' => $this->router->id, 'router_name' => 'UJI', 'code' => 'up-001-uji',
            'profile' => '5-JAM', 'quantity' => 0, 'status' => 'success',
        ]);

        $this->admin = User::create([
            'name' => 'admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('sandi-admin'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($this->admin);
    }

    private static function diRouter(string $nama, string $password = 'p', string $comment = ''): array
    {
        return ['name' => $nama, 'password' => $password, 'profile' => '5-JAM', 'comment' => $comment];
    }

    private function kartu(string $nama, string $password = 'p', array $isi = []): Voucher
    {
        return Voucher::create($isi + [
            'batch_id' => $this->batch->id, 'router_id' => $this->router->id, 'username' => $nama, 'password' => $password,
            'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success',
        ]);
    }

    private static function lengkap(int $nomor): array
    {
        return [
            'status' => 'active', 'expired_at' => now()->subMinutes(10), 'nomor' => $nomor, 'price' => 5000, 'comment' => 'up-001-uji',
            'printed_at' => now()->subDay(), 'uptime_used' => 3600, 'activated_at' => now()->subDay(),
        ];
    }

    private function assertRiwayatSamaDenganSumber(Voucher $sumber, DeletedVoucher $r): void
    {
        foreach (['batch_id', 'router_id', 'nomor', 'username', 'password', 'profile', 'comment', 'price', 'sync_status', 'uptime_used'] as $kol) {
            $this->assertSame($sumber->$kol, $r->$kol, $kol);
        }
        foreach (['activated_at', 'expired_at', 'printed_at'] as $kol) {
            $this->assertSame($sumber->$kol?->format('Y-m-d H:i:s'), $r->$kol?->format('Y-m-d H:i:s'), $kol);
        }
        $this->assertSame([$this->admin->id, 'panel', 'up-001-uji', 'UJI'], [$r->deleted_by, $r->sumber, $r->batch_code, $r->router_name]);
    }

    private function petaHasil(): array
    {
        return DeletedVoucher::orderBy('username')->pluck('hasil_router', 'username')->all();
    }

    private function hasilCabutSemua(iterable $kartu): array
    {
        $hasil = [];
        foreach ($kartu as $v) {
            $hasil[$v->username] = 'dicabut';
        }

        return ['removed' => count($hasil), 'missing' => 0, 'beda_password' => 0, 'gagal' => 0, 'hasil' => $hasil];
    }

    private function hasilPencabutSemua(iterable $kartu): array
    {
        $perKartu = [];
        foreach ($kartu as $v) {
            $perKartu[$v->id] = ['hasil' => 'dicabut', 'router_id' => '*' . $v->id, 'keadaan' => null];
        }

        return [
            'per_kartu' => $perKartu,
            'ringkasan' => array_fill_keys(PencabutKartuService::HASIL, 0) + ['dicabut' => count($perKartu)],
        ];
    }

    public function test_riwayat_memakai_keadaan_router_bila_ada(): void
    {
        $this->mulai();
        $k = $this->kartu('h1', 'p', ['status' => 'ready', 'uptime_used' => 0]);
        $a = now()->subDays(2)->startOfSecond();
        $e = now()->subDay()->startOfSecond();

        $keadaan = ['status' => 'expired', 'expired_at' => $e, 'activated_at' => $a, 'uptime_used' => 3600];
        app(VoucherTerhapusService::class)->simpan([$k->id], 'massal', [$k->id => ['hasil' => 'dicabut', 'keadaan' => $keadaan]], null);

        $r = DeletedVoucher::where('voucher_id', $k->id)->sole();
        $this->assertSame('ready', $k->fresh()->status);
        $this->assertSame(['expired', 3600, 'dicabut'], [$r->status, $r->uptime_used, $r->hasil_router]);
        $this->assertSame($e->format('Y-m-d H:i:s'), $r->expired_at->format('Y-m-d H:i:s'));
        $this->assertSame($a->format('Y-m-d H:i:s'), $r->activated_at->format('Y-m-d H:i:s'));
    }

    public function test_kartu_tanpa_keadaan_dan_kartu_di_luar_peta_memakai_db_dan_tidak_dicabut(): void
    {
        $this->mulai();
        $a = $this->kartu('a', 'p', self::lengkap(1));
        $b = $this->kartu('b');

        app(VoucherTerhapusService::class)->simpan([$a->id, $b->id], 'massal', [$a->id => ['hasil' => 'dicabut', 'keadaan' => null]], null);

        $this->assertSame(['a' => 'dicabut', 'b' => 'tidak_dicabut'], $this->petaHasil());
        $r = DeletedVoucher::where('voucher_id', $a->id)->sole();
        $this->assertSame(['expired', 3600], [$r->status, $r->uptime_used]);
    }

    public function test_dua_kartu_username_sama_beda_router_hasil_tidak_tertukar(): void
    {
        $this->mulai();
        $router2 = Router::create([
            'slug' => 'uji2', 'name' => 'UJI2', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'api', 'password' => 'rahasia',
        ]);
        $batch2 = VoucherBatch::create([
            'router_id' => $router2->id, 'router_name' => 'UJI2', 'code' => 'up-002-uji',
            'profile' => '5-JAM', 'quantity' => 0, 'status' => 'success',
        ]);
        $satu = $this->kartu('sama');
        $dua  = $this->kartu('sama', 'p', ['batch_id' => $batch2->id, 'router_id' => $router2->id]);

        app(VoucherTerhapusService::class)->simpan([$satu->id, $dua->id], 'massal', [
            $satu->id => ['hasil' => 'dicabut', 'keadaan' => null],
            $dua->id  => ['hasil' => 'tidak_ada', 'keadaan' => null],
        ], null);

        $this->assertSame(
            [$satu->id => 'dicabut', $dua->id => 'tidak_ada'],
            DeletedVoucher::pluck('hasil_router', 'voucher_id')->all(),
        );
    }

    public function test_hapus_satu_menulis_riwayat_sama_dengan_baris_sumber(): void
    {
        $this->mulai([self::diRouter('h1', 'p', 'up-001-uji')]);
        $k      = $this->kartu('h1', 'p', self::lengkap(7));
        $sumber = $k->fresh();

        $this->deleteJson(route('vouchers.destroy-one', $k) . '?remove_from_router=1')->assertOk();

        $r = DeletedVoucher::where('voucher_id', $k->id)->sole();
        $this->assertRiwayatSamaDenganSumber($sumber, $r);
        $this->assertSame(['expired', 'dicabut', 'satu'], [$r->status, $r->hasil_router, $r->cara]);
        $this->assertNull(Voucher::find($k->id));
    }

    public function test_hapus_satu_tanpa_cabut_router_hasil_tidak_dicabut(): void
    {
        $this->mulai([self::diRouter('h1')]);
        $k = $this->kartu('h1');

        $this->deleteJson(route('vouchers.destroy-one', $k))->assertOk();

        $this->assertSame(['h1' => 'tidak_dicabut'], $this->petaHasil());
    }

    public function test_hapus_massal_hasil_router_per_kartu(): void
    {
        $this->mulai(
            [self::diRouter('a'), self::diRouter('c', 'lain'), self::diRouter('f'), self::diRouter('g', 'p', 'up-001-uji')],
            ['remove_gagal' => ['f']],
        );
        $kartu = [
            $this->kartu('a'), $this->kartu('b'), $this->kartu('c'),
            $this->kartu('d', 'p', ['sync_status' => 'pending']),
            $this->kartu('e', 'p', ['sync_error' => VoucherService::BENTROK_PREFIX . ': nama sudah dipakai user lain']),
            $this->kartu('f'), $this->kartu('g', 'p', self::lengkap(9)),
        ];
        $sumberG = $kartu[6]->fresh();

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => array_map(fn ($v) => $v->id, $kartu)])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 5, 'ringkasan' => ['beda_password' => 1, 'gagal' => 1]]);

        $this->assertSame([
            'a' => 'dicabut', 'b' => 'tidak_ada', 'd' => 'tidak_dicabut', 'e' => 'tidak_dicabut', 'g' => 'dicabut',
        ], $this->petaHasil());
        $this->assertNotNull(Voucher::where('username', 'c')->first());
        $this->assertNotNull(Voucher::where('username', 'f')->first());
        $this->assertSame(['massal'], DeletedVoucher::distinct()->pluck('cara')->all());

        $g = DeletedVoucher::where('username', 'g')->sole();
        $this->assertRiwayatSamaDenganSumber($sumberG, $g);
        $this->assertSame('expired', $g->status);
        $this->assertSame(1, DeletedVoucher::distinct()->count('deleted_at'));
    }

    public function test_hapus_batch_semua_kartu_tercatat_dan_riwayat_tetap_setelah_batch_hilang(): void
    {
        $this->mulai();
        $sekarang = now();
        $baris    = [];
        for ($i = 1; $i <= 1199; $i++) {
            $baris[] = [
                'batch_id' => $this->batch->id, 'nomor' => $i, 'router_id' => $this->router->id, 'username' => "k{$i}", 'password' => 'p',
                'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success', 'created_at' => $sekarang, 'updated_at' => $sekarang,
            ];
        }
        foreach (array_chunk($baris, 400) as $potong) {
            Voucher::insert($potong);
        }
        $penuh  = $this->kartu('k1200', 'p', self::lengkap(1200));
        $sumber = $penuh->fresh();
        $id     = $this->batch->id;

        $this->deleteJson(route('vouchers.destroy', $this->batch))->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1200, DeletedVoucher::count());
        $this->assertSame(['tidak_dicabut'], DeletedVoucher::distinct()->pluck('hasil_router')->all());
        $this->assertSame(['batch'], DeletedVoucher::distinct()->pluck('cara')->all());
        $this->assertSame(['up-001-uji'], DeletedVoucher::distinct()->pluck('batch_code')->all());
        $this->assertNull(VoucherBatch::find($id));
        $this->assertSame(0, Voucher::where('batch_id', $id)->count());
        $this->assertTrue(DeletedVoucher::where('nomor', 1200)->exists());
        $this->assertRiwayatSamaDenganSumber($sumber, DeletedVoucher::where('voucher_id', $penuh->id)->sole());
    }

    public function test_hapus_batch_dari_router_hasil_per_kartu(): void
    {
        $this->mulai([self::diRouter('x')]);
        $this->kartu('x');
        $this->kartu('y');

        $this->deleteJson(route('vouchers.destroy', $this->batch), ['remove_from_router' => true, 'current_password' => 'sandi-admin'])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(['x' => 'dicabut', 'y' => 'tidak_ada'], $this->petaHasil());
    }

    public function test_gagal_di_tengah_transaksi_riwayat_ikut_batal(): void
    {
        $this->mulai();
        $k = $this->kartu('h1');

        Voucher::deleting(fn () => throw new RuntimeException('uji'));
        $this->deleteJson(route('vouchers.destroy-one', $k))->assertStatus(500);

        $this->assertSame(0, DeletedVoucher::count());
        $this->assertNotNull(Voucher::find($k->id));

        Voucher::flushEventListeners();
        VoucherBatch::deleting(fn () => throw new RuntimeException('uji'));
        $this->deleteJson(route('vouchers.destroy', $this->batch))->assertStatus(500);

        $this->assertSame(0, DeletedVoucher::count());
        $this->assertNotNull(VoucherBatch::find($this->batch->id));
        $this->assertNotNull(Voucher::find($k->id));
    }

    public function test_hapus_massal_gagal_di_tengah_transaksi_riwayat_ikut_batal(): void
    {
        $this->mulai();
        $a = $this->kartu('a', 'p', ['sync_status' => 'pending']);
        $b = $this->kartu('b', 'p', ['sync_status' => 'pending']);

        VoucherBatch::saving(fn () => throw new RuntimeException('uji'));
        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => [$a->id, $b->id]])->assertStatus(500);

        $this->assertSame(0, DeletedVoucher::count());
        $this->assertSame(2, Voucher::whereIn('id', [$a->id, $b->id])->count());
    }

    public function test_kartu_berubah_saat_hapus_massal_409(): void
    {
        $this->mulai([self::diRouter('a'), self::diRouter('b'), self::diRouter('c')]);
        $a = $this->kartu('a');
        $b = $this->kartu('b');
        $c = $this->kartu('c');

        $this->partialMock(PencabutKartuService::class, function ($m) use ($b) {
            $m->shouldReceive('cabut')->andReturnUsing(function ($router, $kartu) use ($b) {
                DB::table('vouchers')->where('id', $b->id)->delete();

                return $this->hasilPencabutSemua($kartu);
            });
        });

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => [$a->id, $b->id, $c->id]])
            ->assertStatus(409)->assertJson(['success' => false, 'error' => 'Kartu berubah, muat ulang halaman.']);

        $this->assertSame(0, DeletedVoucher::count());
        $this->assertSame(2, Voucher::whereIn('id', [$a->id, $c->id])->count());
    }

    public function test_batch_sudah_hilang_saat_dikunci_409(): void
    {
        $this->mulai([self::diRouter('a')]);
        $this->kartu('a');
        $id = $this->batch->id;

        $this->partialMock(PencabutKartuService::class, function ($m) use ($id) {
            $m->shouldReceive('cabut')->andReturnUsing(function ($router, $kartu) use ($id) {
                DB::table('voucher_batches')->where('id', $id)->delete();

                return $this->hasilPencabutSemua($kartu);
            });
        });

        $this->deleteJson(route('vouchers.destroy', $this->batch), ['remove_from_router' => true, 'current_password' => 'sandi-admin'])
            ->assertStatus(409)->assertJson(['success' => false, 'error' => 'Kartu berubah, muat ulang halaman.']);

        $this->assertSame(0, DeletedVoucher::count());
    }
}
