<?php

namespace Tests\Feature;

use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VoucherSale;
use App\Services\CekRentangService;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class CekRentangTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    private Router $router;

    private VoucherBatch $batch;

    private int $idRiwayat = 80000;

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

    private function siapkan(): void
    {
        $besok = strtolower(now()->addDay()->format('M/d/Y H:i:s'));

        $this->palsu = RouterPalsu::mulai([
            ['name' => 'r1', 'password' => 'p', 'profile' => '5-JAM', 'uptime' => '0s', 'limit-uptime' => '5h', 'comment' => 'vc-070-uji'],
            ['name' => 'r2', 'password' => 'lain', 'profile' => '5-JAM', 'uptime' => '0s', 'limit-uptime' => '5h', 'comment' => 'vc-070-uji'],
            ['name' => 'r3', 'password' => 'p', 'profile' => '5-JAM', 'uptime' => '1h', 'limit-uptime' => '5h', 'comment' => $besok],
            ['name' => 'r4', 'password' => 'p', 'profile' => '5-JAM', 'uptime' => '3h', 'limit-uptime' => '1s', 'comment' => 'vc-070-uji'],
            ['name' => 'r5', 'password' => 'p', 'profile' => '5-JAM', 'uptime' => '0s', 'limit-uptime' => '5h', 'comment' => 'vc-070-uji'],
            ['name' => 'R7', 'password' => 'p', 'profile' => '5-JAM', 'uptime' => '0s', 'limit-uptime' => '5h', 'comment' => 'vc-070-uji'],
            ['name' => 'r8', 'password' => 'lain', 'profile' => '5-JAM', 'uptime' => '0s', 'limit-uptime' => '5h', 'comment' => 'vc-070-uji'],
        ], [], [
            '/ip/hotspot/active'       => [['user' => 'r3']],
            '/ip/hotspot/user/profile' => [['name' => '5-JAM', 'on-login' => ':put (",ntfc,5000,1d,0,,Disable,")']],
        ]);

        $this->router = $this->routerBaru('uji', $this->palsu->port);
        $this->batch  = $this->batchBaru('vc-070-uji', $this->router);

        foreach ([1 => 'r1', 2 => 'r2', 3 => 'r3', 4 => 'r4', 6 => 'r6', 7 => 'r7'] as $n => $nama) {
            $this->hidup($this->batch, $nama, $n);
        }
        $this->riwayat($this->batch, 'r5', 5);
        $this->riwayat($this->batch, 'r8', 8);
    }

    private function routerBaru(string $slug, int $port = 1): Router
    {
        return Router::create(['slug' => $slug, 'name' => strtoupper($slug), 'host' => '127.0.0.1', 'port' => $port, 'username' => 'api', 'password' => 'rahasia']);
    }

    private function batchBaru(string $code, Router $router): VoucherBatch
    {
        return VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => $router->name, 'code' => $code, 'label' => 'stok', 'profile' => '5-JAM',
            'quantity' => 0, 'status' => 'success', 'validity' => '1d', 'price' => 5000,
        ]);
    }

    private function hidup(VoucherBatch $batch, string $nama, ?int $nomor, string $password = 'p'): Voucher
    {
        return Voucher::create([
            'batch_id' => $batch->id, 'router_id' => $batch->router_id, 'nomor' => $nomor, 'username' => $nama, 'password' => $password,
            'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success', 'synced_at' => now()->subDay(),
        ]);
    }

    private function riwayat(VoucherBatch $batch, string $nama, ?int $nomor, array $isi = []): DeletedVoucher
    {
        return DeletedVoucher::create($isi + [
            'voucher_id' => ++$this->idRiwayat, 'batch_id' => $batch->id, 'batch_code' => $batch->code, 'router_id' => $batch->router_id,
            'router_name' => $batch->router_name, 'nomor' => $nomor, 'username' => $nama, 'password' => 'p', 'profile' => '5-JAM',
            'price' => 5000, 'status' => 'expired', 'hasil_router' => 'tidak_dicabut', 'cara' => 'massal', 'sumber' => 'panel',
            'deleted_at' => '2026-10-15 21:00:00',
        ]);
    }

    private function cek(array $dari, array $sampai, ?string $batch = null, ?string $router = null): array
    {
        return app(CekRentangService::class)->rentang($dari, $sampai, $batch, $router);
    }

    private function baris(array $h, string $nama): array
    {
        foreach ($h['baris'] as $b) {
            if ($b['username'] === $nama) {
                return $b;
            }
        }

        $this->fail("Baris {$nama} tidak ada");
    }

    private function catatTulis(callable $aksi): array
    {
        $tulis = [];
        DB::listen(function ($q) use (&$tulis) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql)) {
                $tulis[] = $q->sql;
            }
        });
        $aksi();

        return $tulis;
    }

    public function test_rentang_lewat_username_menghitung_status(): void
    {
        $this->siapkan();

        $h = $this->cek(['username' => 'r1'], ['username' => 'r6']);

        $this->assertSame('ok', $h['status']);
        $this->assertSame(['total' => 6, 'belum' => 1, 'aktif' => 1, 'habis' => 1, 'dihapus' => 1, 'bermasalah' => 2], $h['ringkas']);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($h['baris'], 'nomor'));
        $this->assertSame(['Belum dipakai', 'Password di router beda', 'Aktif', 'Habis', 'Dihapus', 'Tidak ada di router'], array_column($h['baris'], 'label'));
        $this->assertTrue($h['baris'][2]['online']);
        $this->assertFalse($h['baris'][0]['online']);
        $this->assertContains('Masih ada di router', $h['baris'][4]['keterangan']);
        $this->assertContains('Dihapus 15 Oct 2026 21:00 (Hapus massal) oleh -', $h['baris'][4]['keterangan']);
        $this->assertContains('Status saat dihapus: Habis', $h['baris'][4]['keterangan']);
        $this->assertContains('1 kartu terhapus masih ada di router.', $h['peringatan']);
        $this->assertTrue($h['bernomor']);
        $this->assertSame(['vc-070-uji', 'UJI', 'stok', 8], [$h['batch']['kode'], $h['batch']['router'], $h['batch']['label'], $h['batch']['jumlah']]);
        $this->assertNotNull($h['batch']['tautan']);
    }

    public function test_rentang_lewat_no_dan_batch(): void
    {
        $this->siapkan();

        $h = $this->cek(['no' => 2], ['no' => 4], 'vc-070-uji');

        $this->assertSame(['r2', 'r3', 'r4'], array_column($h['baris'], 'username'));
    }

    public function test_ujung_terbalik_dibalik_otomatis(): void
    {
        $this->siapkan();

        $this->assertSame([2, 3, 4], array_column($this->cek(['username' => 'r4'], ['username' => 'r2'])['baris'], 'nomor'));
    }

    public function test_ujung_boleh_kartu_terhapus(): void
    {
        $this->siapkan();

        $h = $this->cek(['username' => 'r5'], ['username' => 'r6']);

        $this->assertSame(['Dihapus', 'Tidak ada di router'], array_column($h['baris'], 'label'));
        $this->assertSame(['terhapus', 'hidup'], array_column($h['baris'], 'sumber'));
    }

    public function test_ujung_beda_batch_galat(): void
    {
        $this->siapkan();
        $this->hidup($this->batchBaru('vc-071-uji', $this->router), 's1', 1);

        $h = $this->cek(['username' => 'r1'], ['username' => 's1']);

        $this->assertSame(['galat', 'Kode awal dan akhir ada di batch berbeda (vc-070-uji dan vc-071-uji).'], [$h['status'], $h['pesan']]);
    }

    public function test_lebih_dari_1000_kartu_galat(): void
    {
        $this->siapkan();
        $besar = $this->batchBaru('vc-099-uji', $this->router);
        $baris = [];
        for ($i = 1; $i <= 1001; $i++) {
            $baris[] = [
                'batch_id' => $besar->id, 'nomor' => $i, 'router_id' => $this->router->id, 'username' => "x{$i}", 'password' => 'p',
                'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success', 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($baris, 500) as $potong) {
            Voucher::insert($potong);
        }

        $h = $this->cek(['username' => 'x1'], ['username' => 'x1001']);

        $this->assertSame(['galat', 'Rentang maksimal 1.000 kartu, yang diminta 1.001.'], [$h['status'], $h['pesan']]);
        $this->assertSame('ok', $this->cek(['username' => 'x1'], ['username' => 'x1000'])['status']);
    }

    public function test_ujung_ambigu_memberi_pilihan(): void
    {
        $this->siapkan();
        $this->hidup($this->batch, 'zz', 9);
        $this->hidup($this->batchBaru('vc-072-x', $this->routerBaru('uji2')), 'zz', 1);

        $h = $this->cek(['username' => 'zz'], ['username' => 'r2']);

        $this->assertSame(['ambigu', 'dari'], [$h['status'], $h['ujung']]);
        $this->assertCount(2, $h['pilihan']);

        $h = $this->cek(['username' => 'r2'], ['username' => 'zz']);
        $this->assertSame(['ambigu', 'sampai'], [$h['status'], $h['ujung']]);
    }

    public function test_router_mati_data_panel_dan_peringatan(): void
    {
        $this->siapkan();
        $mati = $this->batchBaru('vc-073-mati', $this->routerBaru('mati'));
        $this->hidup($mati, 'm1', 1);
        $this->hidup($mati, 'm2', 2);

        $h = $this->cek(['username' => 'm1'], ['username' => 'm2']);

        $this->assertSame('ok', $h['status']);
        $this->assertSame(['-', '-'], array_column($h['baris'], 'di_router'));
        $this->assertSame(['Belum dipakai', 'Belum dipakai'], array_column($h['baris'], 'label'));
        $this->assertContains('Router MATI tidak bisa dihubungi, kolom Di router tidak tersedia.', $h['peringatan']);
    }

    public function test_batch_belum_bernomor_urut_id(): void
    {
        $this->siapkan();
        $tanpa = $this->batchBaru('vc-080-uji', $this->router);
        $c     = $this->hidup($tanpa, 'tc', null);
        $a     = $this->hidup($tanpa, 'ta', null);
        $b     = $this->hidup($tanpa, 'tb', null);

        $h = $this->cek(['username' => 'tc'], ['username' => 'tb']);

        $this->assertSame('ok', $h['status']);
        $this->assertFalse($h['bernomor']);
        $this->assertSame(['tc', 'ta', 'tb'], array_column($h['baris'], 'username'));
        $this->assertSame([null, null, null], array_column($h['baris'], 'nomor'));

        $h = $this->cek(['no' => 1], ['no' => 3], 'vc-080-uji');
        $this->assertSame('galat', $h['status']);
        $this->assertStringContainsString('belum dikunci', $h['pesan']);
    }

    public function test_login_pertama_dari_penjualan_paling_awal(): void
    {
        $this->siapkan();
        $jual = function (string $user, string $jam, string $comment) {
            VoucherSale::create([
                'router_id' => $this->router->id, 'router_name' => 'UJI', 'record_hash' => sha1($user . $jam), 'record_name' => "{$user}-{$jam}",
                'sold_at' => today()->setTimeFromTimeString($jam), 'username' => $user, 'profile' => '5-JAM', 'price' => 5000,
                'batch_comment' => $comment,
            ]);
        };
        $jual('r3', '10:00', 'lain');
        $jual('r3', '11:00', 'vc-070-uji');
        $jual('r1', '09:30', 'b');
        $jual('r1', '09:00', 'a');
        $jual('R1', '08:00', 'vc-070-uji');

        $h = $this->cek(['username' => 'r1'], ['username' => 'r3']);

        $this->assertStringContainsString('11:00', $this->baris($h, 'r3')['login_pertama']);
        $this->assertStringContainsString('09:00', $this->baris($h, 'r1')['login_pertama']);
        $this->assertNull($this->baris($h, 'r2')['login_pertama']);
    }

    public function test_cek_rentang_tidak_mengubah_baris_kartu(): void
    {
        $this->siapkan();
        $sebelum      = DB::table('vouchers')->orderBy('id')->get();
        $routerAwal   = $this->palsu->users();

        $tulis = $this->catatTulis(fn () => $this->cek(['username' => 'r1'], ['username' => 'r8']));

        $this->assertSame([], $tulis);
        $this->assertEquals($sebelum, DB::table('vouchers')->orderBy('id')->get());
        $this->assertSame($routerAwal, $this->palsu->users());
        $h = $this->palsu->hitung();
        $this->assertSame(0, $h['add']);
        $this->assertArrayNotHasKey('remove', $h);

        $kontrol = $this->catatTulis(fn () => app(VoucherService::class)->reconcile($this->router->fresh()));
        $this->assertNotEmpty(array_filter($kontrol, fn ($s) => stripos($s, 'update') === 0));
    }

    public function test_beda_huruf_di_router_tidak_dianggap_ada(): void
    {
        $this->siapkan();

        $h = $this->cek(['username' => 'r7'], ['username' => 'r8']);

        $this->assertSame('Tidak ada di router', $this->baris($h, 'r7')['label']);
        $this->assertSame(1, $h['ringkas']['bermasalah']);
        $this->assertNotContains('Masih ada di router', $this->baris($h, 'r8')['keterangan']);
        $this->assertSame([], array_values(array_filter($h['peringatan'], fn ($p) => str_contains($p, 'masih ada di router'))));
    }

    public function test_rentang_batch_yang_sudah_dihapus(): void
    {
        $this->siapkan();
        $this->actingAs(User::create([
            'name' => 'admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('x'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));
        $hapus = $this->batchBaru('vc-090-uji', $this->router);
        foreach ([1, 2, 3] as $n) {
            $this->hidup($hapus, "d{$n}", $n);
        }
        $this->deleteJson(route('vouchers.destroy', $hapus))->assertOk();

        $h = $this->cek(['username' => 'd1'], ['username' => 'd3']);

        $this->assertSame('ok', $h['status']);
        $this->assertSame(['Dihapus', 'Dihapus', 'Dihapus'], array_column($h['baris'], 'label'));
        $this->assertNull($h['batch']['tautan']);
        $this->assertSame(['vc-090-uji', 'UJI', '-', '-', 3], [$h['batch']['kode'], $h['batch']['router'], $h['batch']['validity'], $h['batch']['pembuat'], $h['batch']['jumlah']]);
        $this->assertSame(3, $h['ringkas']['dihapus']);
        $this->assertContains('Dihapus ' . now()->format('d M Y') . ' ' . DeletedVoucher::where('username', 'd1')->value('deleted_at')->format('H:i') . ' (Hapus batch) oleh admin', $h['baris'][0]['keterangan']);
    }

    public function test_rentang_batch_yang_routernya_sudah_dihapus(): void
    {
        $this->siapkan();
        $lama = $this->batchBaru('vc-091-uji', $this->router);
        $this->riwayat($lama, 'e1', 1);
        $this->riwayat($lama, 'e2', 2);
        $lama->delete();
        $this->router->delete();
        $koneksi = $this->palsu->hitung()['koneksi'];

        $h = $this->cek(['username' => 'r1'], ['username' => 'r2']);
        $this->assertSame('ok', $h['status']);
        $this->assertContains('Router batch ini sudah tidak ada di panel, kolom Di router tidak tersedia.', $h['peringatan']);
        $this->assertSame(['-', '-'], array_column($h['baris'], 'di_router'));

        $h = $this->cek(['username' => 'e1'], ['username' => 'e2']);
        $this->assertSame('ok', $h['status']);
        $this->assertContains('Router batch ini sudah tidak ada di panel, kolom Di router tidak tersedia.', $h['peringatan']);
        $this->assertSame($koneksi, $this->palsu->hitung()['koneksi']);
    }

    public function test_penghapus_yang_sudah_tidak_ada_tampil_strip(): void
    {
        $this->siapkan();
        $b = $this->batchBaru('vc-095-uji', $this->router);
        $this->riwayat($b, 'f1', 1, ['deleted_by' => null]);
        $this->riwayat($b, 'f2', 2, ['deleted_by' => 999999]);

        $h = $this->cek(['username' => 'f1'], ['username' => 'f2']);

        foreach ($h['baris'] as $baris) {
            $this->assertContains('Dihapus 15 Oct 2026 21:00 (Hapus massal) oleh -', $baris['keterangan']);
        }
    }

    public function test_ujung_dari_pilihan_kartu(): void
    {
        $this->siapkan();
        $r2 = Voucher::where('username', 'r2')->sole();

        $h = $this->cek(['kartu' => ['sumber' => 'hidup', 'id' => $r2->id]], ['username' => 'r4']);

        $this->assertSame([2, 3, 4], array_column($h['baris'], 'nomor'));
    }

    public function test_peringatan_ujung_diberi_awalan(): void
    {
        $this->siapkan();

        $h = $this->cek(['username' => 'r1', 'no' => 9], ['username' => 'r3', 'password' => 'salah']);

        $this->assertSame(['Awal: r1 tercatat No 1, Anda mengisi 9.', 'Akhir: Password beda dengan catatan panel.'], $h['peringatan_ujung']);
    }
}
