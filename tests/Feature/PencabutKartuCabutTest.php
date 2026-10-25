<?php

namespace Tests\Feature;

use App\Exceptions\RouterSibuk;
use App\Models\ActivityLog;
use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\PencabutKartuService;
use App\Services\RouterGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class PencabutKartuCabutTest extends TestCase
{
    use RefreshDatabase;

    private const PESAN_PUTUS = 'Koneksi ke router putus saat mencabut. Sebagian user mungkin sudah tercabut. Jalankan hapus lagi.';

    private ?RouterPalsu $palsu = null;

    private Router $router;

    private VoucherBatch $batch;

    private array $jam = ['date' => 'sep/26/2026', 'time' => '20:00:00', 'gmt-offset' => '+07:00'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-09-26 20:00:00');
    }

    protected function tearDown(): void
    {
        $this->palsu?->berhenti();
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function mulai(array $users, array $skenario = []): void
    {
        $this->palsu = RouterPalsu::mulai(
            array_map(fn ($u) => $u + ['profile' => '5-JAM', 'uptime' => '0s', 'comment' => 'vc-uji'], $users),
            $skenario,
            [],
            $this->jam,
        );

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->batch = new VoucherBatch([
            'router_id' => $this->router->id, 'router_name' => 'UJI', 'code' => 'vc-uji', 'profile' => '5-JAM', 'quantity' => 5, 'status' => 'success',
        ]);
        $this->batch->created_at = '2026-09-01 00:00:00';
        $this->batch->save();
    }

    private function kartu(string $nama, string $password = 'p', array $lebih = []): Voucher
    {
        return Voucher::create($lebih + [
            'batch_id' => $this->batch->id, 'router_id' => $this->router->id, 'username' => $nama, 'password' => $password,
            'comment' => 'vc-uji', 'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success',
        ]);
    }

    private function cabut(array $kartu, bool $wajibHabis = false): array
    {
        return app(PencabutKartuService::class)->cabut($this->router, collect($kartu), $wajibHabis);
    }

    private function hasil(array $hasil, Voucher $v): string
    {
        return $hasil['per_kartu'][$v->id]['hasil'];
    }

    private function namaDiRouter(): array
    {
        return array_map(fn ($u) => $u['name'] . '/' . $u['password'], $this->palsu->users());
    }

    private function jumlahRemove(): int
    {
        return $this->palsu->hitung()['remove']['/ip/hotspot/user'] ?? 0;
    }

    private function gagalKoneksiPutus(callable $kerja): RuntimeException
    {
        try {
            $kerja();
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(RouterSibuk::class, $e);
            $this->assertSame(self::PESAN_PUTUS, $e->getMessage());

            return $e;
        }

        $this->fail('RuntimeException diharapkan');
    }

    public function test_cabut_menghapus_hanya_baris_cocok_dan_verifikasi(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'lain'], ['name' => 'abc', 'password' => 'p'], ['name' => 'xyz', 'password' => 'p']], ['kembar_boleh' => true]);
        $v = $this->kartu('abc');

        $r = $this->cabut([$v]);

        $this->assertSame('dicabut', $this->hasil($r, $v));
        $this->assertSame('*10001', $r['per_kartu'][$v->id]['router_id']);
        $this->assertSame(1, $this->jumlahRemove());
        $this->assertSame(['abc/lain', 'xyz/p'], $this->namaDiRouter());
        $this->assertSame(1, $r['ringkasan']['dicabut']);
        $this->assertSame(0, $r['ringkasan']['gagal']);
        $this->assertSame(9, count($r['ringkasan']));
    }

    public function test_remove_galat_menjadi_gagal(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p'], ['name' => 'xyz', 'password' => 'p']], ['remove_gagal' => ['abc']]);
        $v = $this->kartu('abc');

        $r = $this->cabut([$v]);

        $this->assertSame('gagal', $this->hasil($r, $v));
        $this->assertSame(1, $r['ringkasan']['gagal']);
        $this->assertSame(0, $r['ringkasan']['dicabut']);
        $this->assertSame(['abc/p', 'xyz/p'], $this->namaDiRouter());
    }

    public function test_no_such_item_tetapi_hilang_menjadi_dicabut(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p'], ['name' => 'xyz', 'password' => 'p']], ['remove_hilang' => ['abc']]);
        $v = $this->kartu('abc');

        $r = $this->cabut([$v]);

        $this->assertSame('dicabut', $this->hasil($r, $v));
        $this->assertSame(1, $r['ringkasan']['dicabut']);
        $this->assertSame(['xyz/p'], $this->namaDiRouter());
    }

    public function test_satu_potongan_campuran_trap_dan_sukses(): void
    {
        $this->mulai(
            [['name' => 'a', 'password' => 'p'], ['name' => 'b', 'password' => 'p'], ['name' => 'c', 'password' => 'p']],
            ['remove_gagal' => ['b']],
        );
        $a = $this->kartu('a');
        $b = $this->kartu('b');
        $c = $this->kartu('c');

        $r = $this->cabut([$a, $b, $c]);

        $this->assertSame(['dicabut', 'gagal', 'dicabut'], [$this->hasil($r, $a), $this->hasil($r, $b), $this->hasil($r, $c)]);
        $this->assertSame(2, $r['ringkasan']['dicabut']);
        $this->assertSame(1, $r['ringkasan']['gagal']);
        $this->assertSame(['b/p'], $this->namaDiRouter());
        $this->assertSame(3, $this->jumlahRemove());
    }

    public function test_potongan_kecil_tetap_mencabut_semua_calon(): void
    {
        config(['voucher.sync_chunk' => 2]);
        $this->mulai([['name' => 'a', 'password' => 'p'], ['name' => 'b', 'password' => 'p'], ['name' => 'c', 'password' => 'p'], ['name' => 'd', 'password' => 'p']]);
        $kartu = [$this->kartu('a'), $this->kartu('b'), $this->kartu('c')];

        $r = $this->cabut($kartu);

        $this->assertSame(3, $r['ringkasan']['dicabut']);
        $this->assertSame(['d/p'], $this->namaDiRouter());
    }

    public function test_id_masih_ada_sesudah_baca_ulang_menjadi_gagal(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p'], ['name' => 'xyz', 'password' => 'p']], ['remove_diam' => ['abc']]);
        $v = $this->kartu('abc');
        $w = $this->kartu('xyz');

        $r = $this->cabut([$v, $w]);

        $this->assertSame('gagal', $this->hasil($r, $v));
        $this->assertSame('dicabut', $this->hasil($r, $w));
        $this->assertSame(1, $r['ringkasan']['gagal']);
        $this->assertSame(1, $r['ringkasan']['dicabut']);
        $this->assertSame(['abc/p'], $this->namaDiRouter());
    }

    public function test_hanya_calon_dicabut_yang_diremove_dan_diberi_penanda(): void
    {
        $this->mulai([['name' => 'ok', 'password' => 'p'], ['name' => 'salah', 'password' => 'lain'], ['name' => 'kembar', 'password' => 'p'], ['name' => 'kembar', 'password' => 'p']], ['kembar_boleh' => true]);
        $ok     = $this->kartu('ok');
        $salah  = $this->kartu('salah');
        $kembar = $this->kartu('kembar');
        $nol    = $this->kartu('tidak-ada');

        $r = $this->cabut([$ok, $salah, $kembar, $nol]);

        $this->assertSame(['dicabut', 'beda_password', 'ambigu', 'tidak_ada'], array_map(fn ($v) => $this->hasil($r, $v), [$ok, $salah, $kembar, $nol]));
        $this->assertSame(1, $this->jumlahRemove());
        $this->assertTrue(Cache::has('pencabut.dicoba.' . $ok->id));
        $this->assertFalse(Cache::has('pencabut.dicoba.' . $salah->id));
        $this->assertFalse(Cache::has('pencabut.dicoba.' . $kembar->id));
        $this->assertFalse(Cache::has('pencabut.dicoba.' . $nol->id));
    }

    public function test_wajib_habis_hanya_mencabut_yang_habis_dan_membawa_keadaan(): void
    {
        $this->mulai([
            ['name' => 'habis', 'password' => 'p', 'limit-uptime' => '1s'],
            ['name' => 'aktif', 'password' => 'p', 'limit-uptime' => '1h'],
        ]);
        $habis = $this->kartu('habis');
        $aktif = $this->kartu('aktif');

        $r = $this->cabut([$habis, $aktif], true);

        $this->assertSame('dicabut', $this->hasil($r, $habis));
        $this->assertSame('expired', $r['per_kartu'][$habis->id]['keadaan']['status']);
        $this->assertSame('belum_habis', $this->hasil($r, $aktif));
        $this->assertSame(['aktif/p'], $this->namaDiRouter());
    }

    public function test_tanpa_calon_tidak_ada_remove_dan_tidak_ada_catatan(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'lain']]);
        $v = $this->kartu('abc');

        $r = $this->cabut([$v]);

        $this->assertSame('beda_password', $this->hasil($r, $v));
        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_cabut_mulai')->count());
    }

    public function test_putus_setelah_remove_melempar_pesan_koneksi_putus(): void
    {
        $this->mulai(
            [['name' => 'a', 'password' => 'rahasia-a'], ['name' => 'b', 'password' => 'rahasia-b'], ['name' => 'c', 'password' => 'rahasia-c']],
            ['putus_setelah_remove' => 1],
        );
        $a = $this->kartu('a', 'rahasia-a');
        $b = $this->kartu('b', 'rahasia-b');
        $c = $this->kartu('c', 'rahasia-c');

        $e = $this->gagalKoneksiPutus(fn () => $this->cabut([$a, $b, $c]));

        $this->assertNotNull($e->getPrevious());
        $this->assertSame(['b/rahasia-b', 'c/rahasia-c'], $this->namaDiRouter());

        $log = ActivityLog::where('action', 'voucher_cabut_mulai')->get();
        $this->assertCount(1, $log);
        $props = $log[0]->properties;
        $this->assertSame('uji', $props['router']);
        $this->assertSame(
            [[$a->id, 'a', '*10000'], [$b->id, 'b', '*10001'], [$c->id, 'c', '*10002']],
            $props['kartu'],
        );
        $this->assertStringNotContainsString('rahasia-', json_encode($log[0]->properties));
        $this->assertStringNotContainsString('rahasia-', (string) $log[0]->description);
        $this->assertTrue(Cache::has('pencabut.dicoba.' . $c->id));
    }

    public function test_putus_saat_baca_ulang_melempar_pesan_koneksi_putus(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p'], ['name' => 'xyz', 'password' => 'p']], ['putus_saat_baca_ulang' => true]);
        $v = $this->kartu('abc');

        $this->gagalKoneksiPutus(fn () => $this->cabut([$v]));

        $this->assertSame(['xyz/p'], $this->namaDiRouter());
        $this->assertSame(1, ActivityLog::where('action', 'voucher_cabut_mulai')->count());
        $this->assertTrue(Cache::has('pencabut.dicoba.' . $v->id));
    }

    public function test_pembatalan_total_dari_baca_tidak_dibungkus_dan_tidak_menulis(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']], ['sembunyikan_password' => true]);
        $v = $this->kartu('abc');

        try {
            $this->cabut([$v]);
            $this->fail('RuntimeException diharapkan');
        } catch (RuntimeException $e) {
            $this->assertSame('Router tidak memberi password user hotspot, hapus dibatalkan.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_cabut_mulai')->count());
        $this->assertFalse(Cache::has('pencabut.dicoba.' . $v->id));
    }

    public function test_router_kosong_dan_jam_salah_dibiarkan_apa_adanya(): void
    {
        $this->mulai([]);
        $v = $this->kartu('abc');

        try {
            $this->cabut([$v]);
            $this->fail('RuntimeException diharapkan');
        } catch (RuntimeException $e) {
            $this->assertSame('Router membalas daftar user kosong, hapus dibatalkan.', $e->getMessage());
        }

        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'abc', 'password' => 'p', 'comment' => 'vc-uji', 'limit-uptime' => '1s']]);
        Carbon::setTestNow('2026-09-26 21:00:00');

        try {
            $this->cabut([$v], true);
            $this->fail('RuntimeException diharapkan');
        } catch (RuntimeException $e) {
            $this->assertSame('Jam router selisih 60 menit dengan server, hapus Habis dibatalkan.', $e->getMessage());
        }

        $this->assertSame(0, $this->jumlahRemove());
    }

    public function test_cabut_menilai_ulang_tidak_memakai_hasil_pratinjau(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']]);
        $v = $this->kartu('abc');
        $service = app(PencabutKartuService::class);

        $this->assertSame('dicabut', $service->nilai($this->router, collect([$v]), false)['per_kartu'][$v->id]['hasil']);

        $this->palsu->hapusBaris('/ip/hotspot/user', 'abc');
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'abc', 'password' => 'lain', 'comment' => 'vc-uji']]);

        $r = $service->cabut($this->router, collect([$v]), false);

        $this->assertSame('beda_password', $this->hasil($r, $v));
        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame(['abc/lain'], $this->namaDiRouter());
    }

    public function test_kunci_tulis_dipegang_melempar_router_sibuk(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']]);
        $v = $this->kartu('abc');
        Carbon::setTestNow();
        $this->assertTrue(Cache::lock('router.tulis.uji', 600)->get());

        try {
            $this->cabut([$v]);
            $this->fail('RouterSibuk diharapkan');
        } catch (RouterSibuk) {
        }

        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame(['abc/p'], $this->namaDiRouter());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_cabut_mulai')->count());
    }

    public function test_ttl_kunci_600_detik(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']]);
        $v = $this->kartu('abc');
        $dikembalikan = ['per_kartu' => [], 'ringkasan' => []];

        $gateway = Mockery::mock(RouterGateway::class);
        $gateway->shouldReceive('kunciTulis')->once()->with('uji', Mockery::type('callable'), 5, 600)->andReturn($dikembalikan);
        $this->app->instance(RouterGateway::class, $gateway);

        $this->assertSame($dikembalikan, app(PencabutKartuService::class)->cabut($this->router, collect([$v]), false));
        $this->assertSame(600, PencabutKartuService::TTL_KUNCI);
        $this->assertSame(5, PencabutKartuService::TUNGGU_KUNCI);
    }

    public function test_ulang_sesudah_putus_tidak_router_sibuk_dan_yang_tercabut_menjadi_tidak_ada(): void
    {
        $this->mulai(
            [['name' => 'a', 'password' => 'p', 'limit-uptime' => '1s'], ['name' => 'b', 'password' => 'p', 'limit-uptime' => '1s']],
            ['putus_setelah_remove' => 1],
        );
        $a = $this->kartu('a');
        $b = $this->kartu('b');

        $this->gagalKoneksiPutus(fn () => $this->cabut([$a, $b], true));

        $r = $this->cabut([$a, $b], true);

        $this->assertSame('tidak_ada', $this->hasil($r, $a));
        $this->assertSame('dicabut', $this->hasil($r, $b));
        $this->assertSame(1, $r['ringkasan']['tidak_ada']);
        $this->assertSame(1, $r['ringkasan']['dicabut']);
        $this->assertSame([], $this->namaDiRouter());
    }
}
