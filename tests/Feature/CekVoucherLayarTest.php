<?php

namespace Tests\Feature;

use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class CekVoucherLayarTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    private Router $router;

    private VoucherBatch $batch;

    private int $idRiwayat = 70000;

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

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'username' => $role . 'uji', 'email' => "{$role}@example.test", 'password' => Hash::make('x'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function siapkan(?array $users = null, string $role = 'admin'): void
    {
        $users ??= [['name' => 'r1', 'password' => 'p'], ['name' => 'r2', 'password' => 'p'], ['name' => 'r3', 'password' => 'p'], ['name' => 'x9', 'password' => '55555']];

        $this->palsu = RouterPalsu::mulai(
            array_map(fn ($u) => $u + ['profile' => '5-JAM', 'uptime' => '0s', 'limit-uptime' => '5h', 'comment' => 'vc-070-uji'], $users),
            [],
            [
                '/ip/hotspot/active'       => [], '/ip/hotspot/host' => [], '/ip/hotspot/cookie' => [],
                '/ip/hotspot/user/profile' => [['name' => '5-JAM', 'on-login' => ':put (",ntfc,5000,1d,0,,Disable,")']],
            ],
        );

        $this->router = Router::create(['slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia']);
        $this->batch  = $this->batchBaru('vc-070-uji', $this->router);

        foreach ([1 => 'r1', 2 => 'r2', 3 => 'r3'] as $n => $nama) {
            $this->hidup($this->batch, $nama, $n);
        }
        $this->riwayat($this->batch, 'r5', 5);

        $this->actingAs($this->pengguna($role));
    }

    private function batchBaru(string $code, Router $router): VoucherBatch
    {
        return VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => $router->name, 'code' => $code, 'profile' => '5-JAM', 'quantity' => 0, 'status' => 'success',
        ]);
    }

    private function hidup(VoucherBatch $batch, string $nama, ?int $nomor, string $password = 'p'): Voucher
    {
        return Voucher::create([
            'batch_id' => $batch->id, 'router_id' => $batch->router_id, 'nomor' => $nomor, 'username' => $nama, 'password' => $password,
            'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success',
        ]);
    }

    private function riwayat(VoucherBatch $batch, string $nama, ?int $nomor, array $isi = []): DeletedVoucher
    {
        return DeletedVoucher::create($isi + [
            'voucher_id' => ++$this->idRiwayat, 'batch_id' => $batch->id, 'batch_code' => $batch->code, 'router_id' => $batch->router_id,
            'router_name' => $batch->router_name, 'nomor' => $nomor, 'username' => $nama, 'password' => 'p', 'status' => 'expired',
            'hasil_router' => 'dicabut', 'cara' => 'massal', 'sumber' => 'panel', 'deleted_at' => '2026-10-15 21:00:00',
        ]);
    }

    private function cari(array $isi)
    {
        return $this->postJson(route('voucher-check.cari'), $isi);
    }

    private function kirimMentah(array $isi)
    {
        return $this->call('POST', route('voucher-check.cari'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($isi));
    }

    public function test_cari_tidak_lagi_menerima_get(): void
    {
        $this->actingAs($this->pengguna('admin'))->get('/admin/cek-voucher/cari?kode=r1')->assertStatus(405);
    }

    public function test_password_dari_url_diabaikan(): void
    {
        $this->actingAs($this->pengguna('admin'))
            ->get(route('voucher-check.index', ['kode' => 'r1', 'pass' => '98765', 'batch' => 'vc-070-uji']))
            ->assertOk()
            ->assertDontSee('98765')
            ->assertSee('value="r1"', false)
            ->assertSee('value="vc-070-uji"', false);
    }

    public function test_satu_kartu_menampilkan_kotak_panel(): void
    {
        $this->siapkan();

        $html = $this->cari(['username' => 'r1'])->assertOk()->json('html');

        $this->assertStringContainsString('Kartu di panel', $html);
        $this->assertStringContainsString('vc-070-uji', $html);
        $this->assertStringContainsString('Belum dipakai', $html);
        $this->assertStringContainsString('Data di router', $html);
    }

    public function test_satu_kartu_terhapus_menampilkan_riwayat(): void
    {
        $this->siapkan();

        $html = $this->cari(['username' => 'r5'])->assertOk()->json('html');

        $this->assertStringContainsString('Sudah dihapus 15 Oct 2026 21:00 (Hapus massal)', $html);
        $this->assertStringContainsString('Dicabut dari router', $html);
    }

    public function test_no_dan_batch_tanpa_username(): void
    {
        $this->siapkan();

        $html = $this->cari(['no' => 2, 'batch' => 'vc-070-uji'])->assertOk()->json('html');

        $this->assertStringContainsString('Kartu di panel', $html);
        $this->assertStringContainsString('r2', $html);
        $this->assertStringContainsString('Data di router', $html);
    }

    public function test_ambigu_memberi_pilihan(): void
    {
        $this->siapkan();
        $this->hidup($this->batch, 'zz', 9, '1');
        $this->hidup($this->batchBaru('vc-072-x', Router::create(['slug' => 'uji2', 'name' => 'UJI2', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x'])), 'zz', 1, '1');

        $r = $this->cari(['username' => 'zz'])->assertOk();

        $this->assertTrue($r->json('ambigu'));
        $this->assertStringContainsString('vc-070-uji', $r->json('html'));
        $this->assertStringContainsString('vc-072-x', $r->json('html'));
        $this->assertStringContainsString('data-pilih-sumber="hidup"', $r->json('html'));
    }

    public function test_tidak_ada_di_panel_tetap_cek_router(): void
    {
        $this->siapkan();

        $html = $this->cari(['username' => 'x9'])->assertOk()->json('html');

        $this->assertStringContainsString('Tidak ada di panel maupun riwayat.', $html);
        $this->assertStringContainsString('Data di router', $html);
    }

    public function test_syarat_minimal_422(): void
    {
        $this->siapkan();

        $this->cari(['batch' => 'vc-070-uji'])->assertStatus(422)
            ->assertJson(['pesan' => 'Isi Username, atau No + Batch, atau Password saja kalau username di kartu tidak terbaca.']);
    }

    public function test_validasi_no_di_luar_batas(): void
    {
        $this->siapkan();

        $this->cari(['no' => 1001, 'batch' => 'vc-070-uji'])->assertStatus(422)->assertJsonValidationErrors('no');
        $this->cari(['no' => 0, 'batch' => 'vc-070-uji'])->assertStatus(422)->assertJsonValidationErrors(['no' => 'No kartu harus 1 sampai 1000.']);
        $this->cari(['username' => str_repeat('a', 65)])->assertStatus(422)->assertJsonValidationErrors(['username' => 'Maksimal 64 karakter.']);
    }

    public function test_operator_boleh_cek(): void
    {
        $this->siapkan(role: 'operator');

        $this->cari(['username' => 'r1'])->assertOk();
        $this->get(route('voucher-check.index'))->assertOk();
    }

    public function test_throttle_ke_31_ditolak_dan_jatah_route_lain_utuh(): void
    {
        $this->siapkan();

        for ($i = 1; $i <= 30; $i++) {
            $this->cari([])->assertStatus(422);
        }

        $this->cari([])->assertStatus(429)->assertJson(['pesan' => 'Terlalu banyak cek, tunggu sebentar.']);
        $this->assertNotSame(429, $this->get(route('vouchers.import.preview', $this->router))->status());
    }

    public function test_input_hanya_dibaca_dari_body(): void
    {
        $this->siapkan();

        $this->postJson(route('voucher-check.cari') . '?username=r1&password=p', [])->assertStatus(422)
            ->assertJson(['pesan' => 'Isi Username, atau No + Batch, atau Password saja kalau username di kartu tidak terbaca.']);
    }

    public function test_cari_satu_kartu_tidak_menulis_ke_db(): void
    {
        $this->siapkan();
        $tulis = [];
        DB::listen(function ($q) use (&$tulis) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql)) {
                $tulis[] = $q->sql;
            }
        });

        $this->cari(['username' => 'r1'])->assertOk();

        $this->assertSame([], $tulis);
    }

    public function test_pilih_kartu_menyelesaikan_ambigu(): void
    {
        $this->siapkan();
        $this->hidup($this->batch, 'zz', 9, '1');
        $b = $this->hidup($this->batchBaru('vc-072-x', Router::create(['slug' => 'uji2', 'name' => 'UJI2', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x'])), 'zz', 1, '1');

        $r = $this->cari(['username' => 'zz', 'kartu' => ['sumber' => 'hidup', 'id' => $b->id]])->assertOk();

        $this->assertNull($r->json('ambigu'));
        $this->assertStringContainsString('vc-072-x', $r->json('html'));
        $this->assertStringContainsString('Kartu di panel', $r->json('html'));
    }

    public function test_body_persis_seperti_browser(): void
    {
        $this->siapkan();
        $r2 = Voucher::where('username', 'r2')->sole();

        $r = $this->kirimMentah(['no' => '2', 'batch' => 'vc-070-uji', 'username' => '', 'password' => '', 'router' => '', 'kartu' => ['sumber' => '', 'id' => '']])->assertOk();
        $this->assertStringContainsString('r2', $r->json('html'));

        $r = $this->kirimMentah(['no' => '', 'batch' => '', 'username' => '', 'password' => '', 'router' => '', 'kartu' => ['sumber' => 'hidup', 'id' => (string) $r2->id]])->assertOk();
        $this->assertStringContainsString('Data di router', $r->json('html'));
        $this->assertStringContainsString('r2', $r->json('html'));

        $this->kirimMentah(['no' => '1001', 'batch' => 'vc-070-uji', 'username' => '', 'password' => ''])->assertStatus(422)->assertJsonValidationErrors('no');
    }

    public function test_password_saja_memakai_mode_lama(): void
    {
        $this->siapkan();

        $html = $this->cari(['password' => '55555'])->assertOk()->json('html');

        $this->assertStringContainsString('Hasil pencarian password', $html);
        $this->assertStringContainsString('x9', $html);
        $this->assertStringNotContainsString('Kartu di panel', $html);
    }

    public function test_no_dan_batch_tidak_ada_tanpa_cek_router(): void
    {
        $this->siapkan();
        $koneksi = $this->palsu->hitung()['koneksi'];

        $html = $this->cari(['no' => 9, 'batch' => 'vc-070-uji'])->assertOk()->json('html');

        $this->assertStringContainsString('Tidak ada di panel maupun riwayat.', $html);
        $this->assertStringNotContainsString('Hasil pencarian password', $html);
        $this->assertSame($koneksi, $this->palsu->hitung()['koneksi']);
    }

    public function test_no_dan_batch_ambigu_memberi_pilihan(): void
    {
        $this->siapkan();
        $baru = $this->batchBaru('vc-tamu1', $this->router);
        $this->hidup($baru, 'tamu1', 1, 'tamu1');
        DeletedVoucher::create([
            'voucher_id' => 99001, 'batch_id' => 99999, 'batch_code' => 'vc-tamu1', 'router_id' => $this->router->id, 'router_name' => 'UJI',
            'nomor' => 1, 'username' => 'tamu1', 'password' => 'tamu1', 'status' => 'expired', 'hasil_router' => 'dicabut', 'cara' => 'batch',
            'sumber' => 'panel', 'deleted_at' => '2026-10-15 21:00:00',
        ]);

        $r = $this->cari(['no' => 1, 'batch' => 'vc-tamu1'])->assertOk();

        $this->assertTrue($r->json('ambigu'));
        $this->assertStringContainsString('data-pilih-sumber="terhapus"', $r->json('html'));
    }

    public function test_kembar_di_router_dipilih_dengan_password_panel(): void
    {
        $this->siapkan([['name' => 'r2', 'password' => 'lain22'], ['name' => 'r2', 'password' => 'kunci22']]);
        Voucher::where('username', 'r2')->update(['password' => 'kunci22']);

        $html = $this->cari(['no' => 2, 'batch' => 'vc-070-uji'])->assertOk()->json('html');

        $i = strpos($html, 'Data di router');
        $this->assertNotFalse($i);
        $j = strpos($html, 'kunci22', $i);
        $k = strpos($html, 'cocok', (int) $j);
        $l = strpos($html, 'user bernama sama di router ini');
        $this->assertNotFalse($j);
        $this->assertNotFalse($k);
        $this->assertNotFalse($l);
        $this->assertTrue($i < $j && $j < $k && $k < $l);
        $this->assertGreaterThan($l, strpos($html, 'lain22', $i));
    }

    private function rentang(array $isi)
    {
        return $this->postJson(route('voucher-check.rentang'), $isi);
    }

    private function siapkanRentang(string $role = 'admin'): void
    {
        $this->siapkan([['name' => 'r1', 'password' => 'p'], ['name' => 'r2', 'password' => 'p'], ['name' => 'r3', 'password' => 'p'], ['name' => 'r5', 'password' => 'p']], $role);
        $this->hidup($this->batch, 'r4', 4);
        $this->hidup($this->batch, 'r6', 6);
    }

    private function routerLain(string $slug): Router
    {
        return Router::create(['slug' => $slug, 'name' => strtoupper($slug), 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);
    }

    public function test_rentang_html_memuat_kepala_ringkasan_dan_tabel(): void
    {
        $this->siapkanRentang();

        $html = $this->rentang(['dari' => ['username' => 'r1'], 'sampai' => ['username' => 'r6']])->assertOk()->json('html');

        foreach (['vc-070-uji', 'Lihat batch', 'Belum dipakai', 'Bermasalah', 'Masih ada di router', 'Tidak ada di router', 'tbl-wrap', 'Dihapus 15 Oct 2026 21:00 (Hapus massal) oleh -'] as $teks) {
            $this->assertStringContainsString($teks, $html, $teks);
        }
        $this->assertSame(1, substr_count($html, '<section class="readout'));
    }

    public function test_rentang_galat_422(): void
    {
        $this->siapkanRentang();
        $this->hidup($this->batchBaru('vc-071-uji', $this->router), 's1', 1);

        $this->rentang(['dari' => ['username' => 'r1'], 'sampai' => ['username' => 's1']])->assertStatus(422)
            ->assertJson(['pesan' => 'Kode awal dan akhir ada di batch berbeda (vc-070-uji dan vc-071-uji).']);
    }

    public function test_rentang_ambigu_memberi_pilihan_ujung(): void
    {
        $this->siapkanRentang();
        $this->hidup($this->batch, 'zz', 8, '1');
        $this->hidup($this->batchBaru('vc-072-x', $this->routerLain('uji2')), 'zz', 1, '1');

        $r = $this->rentang(['dari' => ['username' => 'zz'], 'sampai' => ['username' => 'r2']])->assertOk();

        $this->assertTrue($r->json('ambigu'));
        $this->assertStringContainsString('data-ujung="dari"', $r->json('html'));
        $this->assertStringContainsString('Kode awal ada di 2 kartu', $r->json('html'));
    }

    public function test_rentang_ujung_wajib_username_atau_no_dengan_batch(): void
    {
        $this->siapkanRentang();

        $this->rentang(['dari' => ['no' => 2], 'sampai' => ['username' => 'r4']])->assertStatus(422)
            ->assertJson(['message' => 'Kode awal: isi Username, atau No bila Batch diisi.'])
            ->assertJsonValidationErrors(['dari' => 'Kode awal: isi Username, atau No bila Batch diisi.']);

        $this->rentang(['dari' => ['username' => 'r1'], 'sampai' => ['password' => 'p']])->assertStatus(422)
            ->assertJsonValidationErrors(['sampai' => 'Kode akhir: isi Username, atau No bila Batch diisi.']);

        $this->rentang(['dari' => ['username' => 'r1']])->assertStatus(422)->assertJsonValidationErrors('sampai');
    }

    public function test_operator_boleh_cek_rentang(): void
    {
        $this->siapkanRentang('operator');

        $this->rentang(['dari' => ['username' => 'r1'], 'sampai' => ['username' => 'r3']])->assertOk();
    }

    public function test_rentang_body_persis_seperti_browser(): void
    {
        $this->siapkanRentang();
        $kirim = fn (array $isi) => $this->call('POST', route('voucher-check.rentang'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($isi));
        $ujung = fn (string $no) => ['no' => $no, 'username' => '', 'password' => '', 'kartu' => ['sumber' => '', 'id' => '']];

        $html = $kirim(['dari' => $ujung('2'), 'sampai' => $ujung('4'), 'batch' => 'vc-070-uji', 'router' => ''])->assertOk()->json('html');

        $this->assertStringContainsString('<td class="mono">r2</td>', $html);
        $this->assertStringContainsString('<td class="mono">r4</td>', $html);
        $this->assertStringNotContainsString('<td class="mono">r1</td>', $html);

        $kirim(['dari' => $ujung('1001'), 'sampai' => $ujung('4'), 'batch' => 'vc-070-uji', 'router' => ''])->assertStatus(422)->assertJsonValidationErrors('dari.no');
    }

    public function test_rentang_pilihan_dua_ujung(): void
    {
        $this->siapkanRentang();
        $zz = $this->hidup($this->batch, 'zz', 8, '1');
        $yy = $this->hidup($this->batch, 'yy', 9, '1');
        $lain = $this->batchBaru('vc-072-x', $this->routerLain('uji2'));
        $this->hidup($lain, 'zz', 1, '1');
        $this->hidup($lain, 'yy', 2, '1');

        $r = $this->rentang(['dari' => ['kartu' => ['sumber' => 'hidup', 'id' => $zz->id]], 'sampai' => ['username' => 'yy']])->assertOk();
        $this->assertTrue($r->json('ambigu'));
        $this->assertStringContainsString('data-ujung="sampai"', $r->json('html'));

        $r = $this->rentang(['dari' => ['kartu' => ['sumber' => 'hidup', 'id' => $zz->id]], 'sampai' => ['kartu' => ['sumber' => 'hidup', 'id' => $yy->id]]])->assertOk();
        $this->assertNull($r->json('ambigu'));
        $this->assertStringContainsString('<td class="mono">zz</td>', $r->json('html'));
        $this->assertStringContainsString('<td class="mono">yy</td>', $r->json('html'));
    }

    public function test_rentang_keadaan_tepi_tampil_tanpa_galat(): void
    {
        $this->siapkanRentang();

        $hapus = $this->batchBaru('vc-090-uji', $this->router);
        $this->hidup($hapus, 'd1', 1);
        $this->hidup($hapus, 'd2', 2);
        $this->deleteJson(route('vouchers.destroy', $hapus))->assertOk();
        $html = $this->rentang(['dari' => ['username' => 'd1'], 'sampai' => ['username' => 'd2']])->assertOk()->json('html');
        $this->assertStringNotContainsString('Lihat batch', $html);
        $this->assertStringContainsString('Masa aktif</span><span class="v">-</span>', $html);

        $tanpaRouter = $this->routerLain('lama');
        $b = $this->batchBaru('vc-091-lama', $tanpaRouter);
        $this->hidup($b, 'e1', 1);
        $this->hidup($b, 'e2', 2);
        $tanpaRouter->delete();
        $this->assertStringContainsString('Router batch ini sudah tidak ada di panel', $this->rentang(['dari' => ['username' => 'e1'], 'sampai' => ['username' => 'e2']])->assertOk()->json('html'));

        $mati = $this->batchBaru('vc-092-mati', $this->routerLain('mati'));
        $this->hidup($mati, 'm1', 1);
        $this->hidup($mati, 'm2', 2);
        $this->assertStringContainsString('tidak bisa dihubungi', $this->rentang(['dari' => ['username' => 'm1'], 'sampai' => ['username' => 'm2']])->assertOk()->json('html'));

        $tanpa = $this->batchBaru('vc-093-uji', $this->router);
        $this->hidup($tanpa, 't1', null);
        $this->hidup($tanpa, 't2', null);
        $html = $this->rentang(['dari' => ['username' => 't1'], 'sampai' => ['username' => 't2']])->assertOk()->json('html');
        $this->assertStringContainsString('Nomor kartu batch ini belum dikunci.', $html);
        $this->assertStringContainsString('<td class="ix">-</td>', $html);

        $this->assertStringContainsString('oleh -', $this->rentang(['dari' => ['username' => 'r1'], 'sampai' => ['username' => 'r5']])->assertOk()->json('html'));
    }

    public function test_kotak_panel_penghapus_yang_sudah_tidak_ada(): void
    {
        $this->siapkan();
        $this->riwayat($this->batch, 'r6', 6, ['deleted_by' => null]);
        $this->riwayat($this->batch, 'r7', 7, ['deleted_by' => 999999]);

        foreach (['r6', 'r7'] as $nama) {
            $this->assertStringContainsString('oleh -', $this->cari(['username' => $nama])->assertOk()->json('html'), $nama);
        }
    }
}
