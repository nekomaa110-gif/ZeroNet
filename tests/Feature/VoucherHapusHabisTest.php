<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherHapusHabisTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    private ?Router $router = null;

    private ?VoucherBatch $batch = null;

    private array $jam = ['date' => 'sep/26/2026', 'time' => '20:00:00', 'gmt-offset' => '+07:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Carbon::setTestNow('2026-09-26 20:00:00');
    }

    protected function tearDown(): void
    {
        $this->palsu?->berhenti();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mulai(array $users, array $skenario = [], ?array $jam = null, bool $adaRouter = true): void
    {
        $this->palsu = RouterPalsu::mulai(
            array_map(fn ($u) => $u + ['profile' => '5-JAM', 'uptime' => '0s', 'comment' => 'vc-hab'], $users),
            $skenario,
            [],
            $jam ?? $this->jam,
        );

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->batch = new VoucherBatch([
            'router_id' => $adaRouter ? $this->router->id : null, 'router_name' => 'UJI', 'code' => 'vc-hab', 'profile' => '5-JAM', 'quantity' => 0, 'status' => 'success',
        ]);
        $this->batch->created_at = '2026-09-01 00:00:00';
        $this->batch->save();

        $this->actingAs(User::create([
            'name' => 'admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('sandi-admin'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));
    }

    private function kartu(string $nama, array $lebih = []): Voucher
    {
        $v = Voucher::create($lebih + [
            'batch_id' => $this->batch->id, 'router_id' => $this->batch->router_id, 'username' => $nama, 'password' => 'p',
            'comment' => 'vc-hab', 'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success',
        ]);
        $this->batch->increment('quantity');

        return $v;
    }

    private function id(string ...$nama): array
    {
        return Voucher::whereIn('username', $nama)->pluck('id')->all();
    }

    private function ada(string ...$nama): array
    {
        return Voucher::whereIn('username', $nama)->orderBy('username')->pluck('username')->all();
    }

    private function diRouter(): array
    {
        return array_column($this->palsu->users(), 'name');
    }

    private function jumlahRemove(): int
    {
        return $this->palsu->hitung()['remove']['/ip/hotspot/user'] ?? 0;
    }

    private function hapus(array $isi)
    {
        return $this->deleteJson(route('vouchers.destroy-many', $this->batch), $isi + ['password' => 'sandi-admin']);
    }

    private function pratinjau(array $isi)
    {
        return $this->postJson(route('vouchers.preview-destroy-many', $this->batch), $isi);
    }

    private function mulaiEmpat(): void
    {
        $this->mulai([
            ['name' => 'h1', 'password' => 'p', 'limit-uptime' => '1s'],
            ['name' => 'h2', 'password' => 'p', 'limit-uptime' => '5h', 'uptime' => '1m'],
        ]);
        $this->kartu('h1', ['status' => 'active', 'expired_at' => now()->addDay()]);
        $this->kartu('h2', ['status' => 'expired']);
        $this->kartu('h3', ['status' => 'expired']);
        $this->kartu('h4');
    }

    public function test_hapus_habis_semua_mengabaikan_status_db_dan_mengikuti_router(): void
    {
        $this->mulaiEmpat();

        $res = $this->hapus(['mode' => 'habis', 'semua' => true, 'jumlah' => 4])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 2]);

        $this->assertSame(1, $res->json('ringkasan.dicabut'));
        $this->assertSame(1, $res->json('ringkasan.tidak_ada'));
        $this->assertSame(1, $res->json('ringkasan.belum_habis'));
        $this->assertSame(1, $res->json('ringkasan.tidak_di_router'));
        $this->assertSame(
            [
                ['nomor' => null, 'username' => 'h2', 'alasan' => 'Di router belum habis'],
                ['nomor' => null, 'username' => 'h4', 'alasan' => 'Tidak ada di router, padahal di panel belum habis'],
            ],
            $res->json('ditahan'),
        );
        $this->assertSame(['h2', 'h4'], $this->ada('h1', 'h2', 'h3', 'h4'));
        $this->assertSame(['h2'], $this->diRouter());
        $this->assertSame(2, $this->batch->fresh()->quantity);
        $this->assertSame(['h1' => 'dicabut', 'h3' => 'tidak_ada'], DeletedVoucher::orderBy('username')->pluck('hasil_router', 'username')->all());

        $log = ActivityLog::where('action', 'voucher_delete_many')->sole();
        $this->assertTrue($log->properties['wajib_habis']);
        $this->assertSame(['belum_habis' => 1, 'tidak_di_router' => 1], $log->properties['ditahan']);
    }

    public function test_hapus_habis_semua_mengabaikan_status_walau_filter_status_ikut_terkirim(): void
    {
        $this->mulaiEmpat();

        $this->pratinjau(['mode' => 'habis', 'semua' => true, 'status' => 'expired'])
            ->assertOk()->assertJson(['kandidat' => 4]);

        $this->hapus(['mode' => 'habis', 'semua' => true, 'status' => 'expired', 'jumlah' => 4])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 2]);

        $this->assertSame(['h2', 'h4'], $this->ada('h1', 'h2', 'h3', 'h4'));
    }

    public function test_hapus_habis_pilihan_tetap_wajib_habis(): void
    {
        $this->mulaiEmpat();

        $this->hapus(['mode' => 'habis', 'ids' => $this->id('h1', 'h2')])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 1]);

        $this->assertSame(['h2', 'h3', 'h4'], $this->ada('h1', 'h2', 'h3', 'h4'));
        $this->assertSame(['h2'], $this->diRouter());
    }

    public function test_tanpa_mode_habis_filter_status_habis_tidak_wajib_habis(): void
    {
        $this->mulaiEmpat();

        $res = $this->hapus(['semua' => true, 'status' => 'expired', 'jumlah' => 2])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 2]);

        $this->assertSame(1, $res->json('ringkasan.dicabut'));
        $this->assertSame(1, $res->json('ringkasan.tidak_ada'));
        $this->assertSame(['h1', 'h4'], $this->ada('h1', 'h2', 'h3', 'h4'));
        $this->assertSame(['h1'], $this->diRouter());
        $this->assertFalse(ActivityLog::where('action', 'voucher_delete_many')->sole()->properties['wajib_habis']);
    }

    public function test_hapus_habis_router_kosong_batal_tanpa_menghapus(): void
    {
        $this->mulai([]);
        $this->kartu('h1');
        $this->kartu('h3', ['status' => 'expired']);

        $this->hapus(['mode' => 'habis', 'semua' => true, 'jumlah' => 2])
            ->assertStatus(422)->assertJson(['success' => false, 'error' => 'Router membalas daftar user kosong, hapus dibatalkan.']);
        $this->pratinjau(['mode' => 'habis', 'semua' => true])
            ->assertStatus(422)->assertJson(['success' => false, 'error' => 'Router membalas daftar user kosong, hapus dibatalkan.']);

        $this->assertSame(['h1', 'h3'], $this->ada('h1', 'h3'));
        $this->assertSame(0, DeletedVoucher::count());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_delete_many')->count());
    }

    public function test_hapus_habis_jam_router_salah_batal_tanpa_menghapus(): void
    {
        $this->mulai(
            [['name' => 'h1', 'password' => 'p', 'limit-uptime' => '1s']],
            [],
            ['date' => 'sep/26/2026', 'time' => '21:00:00', 'gmt-offset' => '+07:00'],
        );
        $this->kartu('h1');

        $pesan = 'Jam router selisih 60 menit dengan server, hapus Habis dibatalkan.';
        $this->hapus(['mode' => 'habis', 'semua' => true, 'jumlah' => 1])
            ->assertStatus(422)->assertJson(['success' => false, 'error' => $pesan]);
        $this->pratinjau(['mode' => 'habis', 'semua' => true])
            ->assertStatus(422)->assertJson(['success' => false, 'error' => $pesan]);

        $this->assertSame(['h1'], $this->ada('h1'));
        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame(0, DeletedVoucher::count());
    }

    public function test_hapus_habis_kartu_bentrok_dan_belum_terkirim_tidak_ikut(): void
    {
        $this->mulai([
            ['name' => 'h1', 'password' => 'p', 'limit-uptime' => '1s'],
            ['name' => 'hb', 'password' => 'p', 'limit-uptime' => '1s'],
        ]);
        $this->kartu('h1');
        $this->kartu('hb', ['status' => 'expired', 'sync_error' => VoucherService::BENTROK_PREFIX . ': nama sudah dipakai user lain']);
        $this->kartu('hp', ['status' => 'expired', 'sync_status' => 'pending']);

        $res = $this->pratinjau(['mode' => 'habis', 'semua' => true])->assertOk();
        $this->assertSame(1, $res->json('kandidat'));

        $this->hapus(['mode' => 'habis', 'semua' => true, 'jumlah' => 1])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 1]);

        $this->assertSame(['hb', 'hp'], $this->ada('h1', 'hb', 'hp'));
        $this->assertSame(['hb'], $this->diRouter());
        $this->assertSame(['h1'], DeletedVoucher::pluck('username')->all());

        VoucherBatch::whereKey($this->batch->id)->update(['status' => 'success']);
        $this->hapus(['mode' => 'habis', 'ids' => $this->id('hb', 'hp')])
            ->assertStatus(422)->assertJson(['success' => false, 'error' => 'Tidak ada kartu yang memenuhi syarat untuk dihapus.']);
        $this->assertSame(['hb', 'hp'], $this->ada('h1', 'hb', 'hp'));
    }

    public function test_hapus_habis_riwayat_berisi_status_router(): void
    {
        $this->mulai([['name' => 'h1', 'password' => 'p', 'limit-uptime' => '1s', 'uptime' => '30m']]);
        $this->kartu('h1', ['status' => 'active', 'expired_at' => now()->addDay(), 'uptime_used' => 0]);

        $this->hapus(['mode' => 'habis', 'semua' => true, 'jumlah' => 1])->assertOk();

        $riwayat = DeletedVoucher::sole();
        $this->assertSame('expired', $riwayat->status);
        $this->assertSame(1800, $riwayat->uptime_used);
        $this->assertSame('dicabut', $riwayat->hasil_router);
        $this->assertSame('massal', $riwayat->cara);
        $this->assertNull($riwayat->expired_at);
    }

    public function test_pratinjau_lalu_router_berubah_eksekusi_mengikuti_router(): void
    {
        $this->mulai([
            ['name' => 'h1', 'password' => 'p', 'limit-uptime' => '1s'],
            ['name' => 'h2', 'password' => 'p', 'limit-uptime' => '1s'],
        ]);
        $this->kartu('h1');
        $this->kartu('h2');

        $res = $this->pratinjau(['mode' => 'habis', 'semua' => true])->assertOk();
        $this->assertSame(2, $res->json('kandidat'));
        $this->assertSame(2, $res->json('ringkasan.dicabut'));

        $this->palsu->hapusBaris('/ip/hotspot/user', 'h1');
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'h1', 'password' => 'p', 'profile' => '5-JAM', 'uptime' => '1m', 'limit-uptime' => '5h', 'comment' => 'vc-hab']]);

        $eksekusi = $this->hapus(['mode' => 'habis', 'semua' => true, 'jumlah' => $res->json('kandidat')])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 1]);

        $this->assertSame(1, $eksekusi->json('ringkasan.belum_habis'));
        $this->assertSame(['h1'], $this->ada('h1', 'h2'));
        $this->assertSame(['h1'], $this->diRouter());
    }

    public function test_hapus_habis_batch_tanpa_router_422_tanpa_menghapus(): void
    {
        $this->mulai([], [], null, false);
        $this->kartu('h1', ['router_id' => null]);
        $this->kartu('h2', ['router_id' => null, 'status' => 'expired']);
        $pesan = 'Batch ini tidak punya router, Habis tidak bisa dibuktikan.';

        $this->pratinjau(['mode' => 'habis', 'semua' => true])
            ->assertStatus(422)->assertJson(['success' => false, 'error' => $pesan]);
        $this->hapus(['mode' => 'habis', 'semua' => true, 'jumlah' => 2])
            ->assertStatus(422)->assertJson(['success' => false, 'error' => $pesan]);
        $this->hapus(['mode' => 'habis', 'ids' => $this->id('h1')])
            ->assertStatus(422)->assertJson(['success' => false, 'error' => $pesan]);

        $this->assertSame(['h1', 'h2'], $this->ada('h1', 'h2'));
        $this->assertSame(0, DeletedVoucher::count());
        $this->assertSame(0, $this->palsu->hitung()['koneksi']);
    }

    public function test_hapus_habis_lewat_http_tanggal_sebelum_batch_ditahan(): void
    {
        $this->mulai([['name' => 'h1', 'password' => 'p', 'comment' => 'jan/01/2002 00:00:00']]);
        $this->kartu('h1');

        $res = $this->pratinjau(['mode' => 'habis', 'semua' => true])->assertOk();
        $this->assertSame('Di router belum habis', $res->json('ditahan.0.alasan'));
        $this->assertSame(1, $res->json('ringkasan.belum_habis'));

        $this->hapus(['mode' => 'habis', 'semua' => true, 'jumlah' => 1])->assertStatus(422);

        $this->assertSame(['h1'], $this->ada('h1'));
        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame(0, DeletedVoucher::count());
    }

    public function test_hapus_habis_lewat_http_habis_karena_tanggal_riwayat_berisi_expired_at_router(): void
    {
        $this->mulai([['name' => 'h1', 'password' => 'p', 'comment' => 'sep/26/2026 10:00:00', 'uptime' => '5m']]);
        $this->kartu('h1');

        $this->hapus(['mode' => 'habis', 'semua' => true, 'jumlah' => 1])->assertOk()->assertJson(['jumlah' => 1]);

        $riwayat = DeletedVoucher::sole();
        $this->assertSame('expired', $riwayat->status);
        $this->assertSame('2026-09-26 10:00:00', $riwayat->expired_at->format('Y-m-d H:i:s'));
        $this->assertSame(300, $riwayat->uptime_used);
        $this->assertSame([], $this->diRouter());
    }

    public static function kombinasi(): array
    {
        return [
            'biasa semua'  => [null, true, 4],
            'biasa pilih'  => [null, false, 4],
            'habis semua'  => ['habis', true, 2],
            'habis pilih'  => ['habis', false, 2],
        ];
    }

    #[DataProvider('kombinasi')]
    public function test_pratinjau_kandidat_dipakai_sebagai_jumlah_berhasil(?string $mode, bool $semua, int $kandidat): void
    {
        $this->mulai([
            ['name' => 'n1', 'password' => 'p', 'limit-uptime' => '1s'],
            ['name' => 'n2', 'password' => 'p', 'limit-uptime' => '1s'],
            ['name' => 'nb', 'password' => 'p', 'limit-uptime' => '1s'],
        ]);
        $this->kartu('n1');
        $this->kartu('n2');
        $this->kartu('nb', ['sync_error' => VoucherService::BENTROK_PREFIX . ': nama sudah dipakai user lain']);
        $this->kartu('np', ['sync_status' => 'pending']);

        $isi = array_filter(['mode' => $mode]) + ($semua ? ['semua' => true] : ['ids' => $this->id('n1', 'n2', 'nb', 'np')]);

        $res = $this->pratinjau($isi)->assertOk();
        $this->assertSame($kandidat, $res->json('kandidat'));

        preg_match('/(\d+) dicabut dari router, (\d+) sudah tidak ada di router, (\d+) dihapus dari panel saja, (\d+) ditahan\./', $res->json('pesan'), $angka);
        $this->assertSame($kandidat, (int) $angka[1] + (int) $angka[2] + (int) $angka[3] + (int) $angka[4]);

        $eksekusi = $this->hapus($isi + ['jumlah' => $res->json('kandidat')]);
        $eksekusi->assertOk()->assertJson(['success' => true, 'jumlah' => $kandidat]);
        $this->assertSame(4 - $kandidat, Voucher::count());
    }
}
