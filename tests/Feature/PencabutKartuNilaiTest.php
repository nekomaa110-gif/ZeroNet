<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\PencabutKartuService;
use App\Services\VoucherLookupService;
use App\Support\MikhmonRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class PencabutKartuNilaiTest extends TestCase
{
    use RefreshDatabase;

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
        parent::tearDown();
    }

    private function mulai(array $users, array $skenario = [], ?array $jam = null, array $batch = []): void
    {
        $this->palsu = RouterPalsu::mulai(
            array_map(fn ($u) => $u + ['profile' => '5-JAM', 'uptime' => '0s', 'comment' => 'vc-uji'], $users),
            $skenario,
            [],
            $jam ?? $this->jam,
        );

        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->batch = new VoucherBatch($batch + [
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

    private function nilai(array $kartu, bool $wajibHabis = false): array
    {
        return app(PencabutKartuService::class)->nilai($this->router, collect($kartu), $wajibHabis);
    }

    private function hasil(array $hasil, Voucher $v): string
    {
        return $hasil['per_kartu'][$v->id]['hasil'];
    }

    private function batal(callable $kerja, string $pesan): void
    {
        try {
            $kerja();
            $this->fail('RuntimeException diharapkan');
        } catch (RuntimeException $e) {
            $this->assertSame($pesan, $e->getMessage());
        }
    }

    public function test_tidak_ada_nama_menjadi_tidak_ada(): void
    {
        $this->mulai([['name' => 'lain', 'password' => 'p']]);
        $v = $this->kartu('abc');

        $this->assertSame('tidak_ada', $this->hasil($this->nilai([$v]), $v));
    }

    public function test_satu_baris_cocok_kode_batch_menjadi_calon_dicabut(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']]);
        $v = $this->kartu('abc');

        $r = $this->nilai([$v]);

        $this->assertSame('dicabut', $this->hasil($r, $v));
        $this->assertSame('*10000', $r['per_kartu'][$v->id]['router_id']);
        $this->assertNull($r['per_kartu'][$v->id]['keadaan']);
    }

    public function test_kartu_missing_cocok_hanya_lewat_tanggal_ditahan_tidak_terbukti(): void
    {
        $this->mulai([['name' => 'a', 'password' => 'p', 'comment' => '2026-09-20 10:00:00']]);
        $v = $this->kartu('a', 'p', ['status' => 'missing']);

        $r = $this->nilai([$v]);

        $this->assertSame('tidak_terbukti', $this->hasil($r, $v));
        $this->assertNull($r['per_kartu'][$v->id]['router_id']);
        $this->assertSame(1, $r['ringkasan']['tidak_terbukti']);
    }

    public function test_kartu_missing_cocok_lewat_kode_batch_tetap_dicabut(): void
    {
        $this->mulai([['name' => 'a', 'password' => 'p', 'comment' => 'vc-uji']]);
        $v = $this->kartu('a', 'p', ['status' => 'missing']);

        $this->assertSame('dicabut', $this->hasil($this->nilai([$v]), $v));
    }

    public function test_kartu_tidak_missing_cocok_lewat_tanggal_tetap_dicabut(): void
    {
        $this->mulai([['name' => 'a', 'password' => 'p', 'comment' => '2026-09-20 10:00:00']]);
        $v = $this->kartu('a', 'p', ['status' => 'active']);

        $this->assertSame('dicabut', $this->hasil($this->nilai([$v]), $v));
    }

    public function test_kartu_missing_cocok_tanggal_ditahan_juga_di_mode_habis(): void
    {
        $this->mulai([['name' => 'a', 'password' => 'p', 'comment' => '2026-09-20 10:00:00', 'limit-uptime' => '1s']]);
        $v = $this->kartu('a', 'p', ['status' => 'missing']);

        $this->assertSame('tidak_terbukti', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_comment_tanggal_iso_dan_format_lama_dianggap_cocok(): void
    {
        $this->mulai([
            ['name' => 'a', 'password' => 'p', 'comment' => '2026-09-20 10:00:00'],
            ['name' => 'b', 'password' => 'p', 'comment' => 'sep/20/2026 10:00:00'],
        ]);
        $a = $this->kartu('a');
        $b = $this->kartu('b');

        $r = $this->nilai([$a, $b]);

        $this->assertSame(['dicabut', 'dicabut'], [$this->hasil($r, $a), $this->hasil($r, $b)]);
    }

    public function test_dua_baris_cocok_menjadi_ambigu(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p'], ['name' => 'abc', 'password' => 'p']], ['kembar_boleh' => true]);
        $v = $this->kartu('abc');

        $r = $this->nilai([$v]);

        $this->assertSame('ambigu', $this->hasil($r, $v));
        $this->assertNull($r['per_kartu'][$v->id]['router_id']);
    }

    public function test_password_beda_menjadi_beda_password(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'lain']]);
        $v = $this->kartu('abc');

        $this->assertSame('beda_password', $this->hasil($this->nilai([$v]), $v));
    }

    public function test_password_sama_comment_batch_lain_menjadi_beda_batch(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'comment' => 'vc-lain']]);
        $v = $this->kartu('abc');

        $this->assertSame('beda_batch', $this->hasil($this->nilai([$v]), $v));
    }

    public function test_baris_lain_bernama_sama_password_lain_tidak_menghalangi(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'lain'], ['name' => 'abc', 'password' => 'p']], ['kembar_boleh' => true]);
        $v = $this->kartu('abc');

        $r = $this->nilai([$v]);

        $this->assertSame('dicabut', $this->hasil($r, $v));
        $this->assertSame('*10001', $r['per_kartu'][$v->id]['router_id']);
    }

    public function test_nama_beda_huruf_tidak_dianggap_sama(): void
    {
        $this->mulai([['name' => 'ABC', 'password' => 'p']]);
        $v = $this->kartu('abc');

        $this->assertSame('tidak_ada', $this->hasil($this->nilai([$v]), $v));
    }

    public function test_comment_null_di_panel_cocok_dengan_comment_kosong_di_router(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'comment' => '']]);
        $v = $this->kartu('abc', 'p', ['comment' => null]);

        $this->assertNull($v->fresh()->comment);
        $this->assertSame('dicabut', $this->hasil($this->nilai([$v->fresh()]), $v));
    }

    public function test_router_tanpa_password_batal_total(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']], ['sembunyikan_password' => true]);
        $v = $this->kartu('abc');

        $this->batal(fn () => $this->nilai([$v]), 'Router tidak memberi password user hotspot, hapus dibatalkan.');
    }

    public function test_router_kosong_dengan_kartu_tersinkron_batal_total(): void
    {
        $this->mulai([]);
        $v = $this->kartu('abc');

        $this->batal(fn () => $this->nilai([$v]), 'Router membalas daftar user kosong, hapus dibatalkan.');
        $this->batal(fn () => $this->nilai([$v], true), 'Router membalas daftar user kosong, hapus dibatalkan.');
    }

    public function test_router_kosong_dengan_kartu_belum_terkirim_tidak_batal(): void
    {
        $this->mulai([]);
        $v = $this->kartu('abc', 'p', ['sync_status' => 'pending']);

        $this->assertSame('tidak_ada', $this->hasil($this->nilai([$v]), $v));
    }

    public function test_wajib_habis_limit_uptime_1s_dicabut(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'limit-uptime' => '1s']]);
        $v = $this->kartu('abc');

        $r = $this->nilai([$v], true);

        $this->assertSame('dicabut', $this->hasil($r, $v));
        $this->assertSame('expired', $r['per_kartu'][$v->id]['keadaan']['status']);
    }

    public function test_wajib_habis_uptime_melebihi_jatah_dicabut(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'uptime' => '1h1m', 'limit-uptime' => '1h']]);
        $v = $this->kartu('abc');

        $r = $this->nilai([$v], true);

        $this->assertSame('dicabut', $this->hasil($r, $v));
        $this->assertSame(3660, $r['per_kartu'][$v->id]['keadaan']['uptime_used']);
    }

    public function test_wajib_habis_comment_lewat_dicabut_dan_keadaan_dibawa(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'comment' => 'sep/26/2026 10:00:00', 'uptime' => '5m']]);
        $v = $this->kartu('abc');

        $r = $this->nilai([$v], true);

        $this->assertSame('dicabut', $this->hasil($r, $v));
        $this->assertSame('expired', $r['per_kartu'][$v->id]['keadaan']['status']);
        $this->assertNotNull($r['per_kartu'][$v->id]['keadaan']['expired_at']);
        $this->assertSame('2026-09-26 10:00:00', $r['per_kartu'][$v->id]['keadaan']['expired_at']->format('Y-m-d H:i:s'));
    }

    public function test_wajib_habis_comment_belum_lewat_belum_habis(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'comment' => 'sep/26/2026 23:00:00', 'uptime' => '5m']]);
        $v = $this->kartu('abc');

        $r = $this->nilai([$v], true);

        $this->assertSame('belum_habis', $this->hasil($r, $v));
        $this->assertNull($r['per_kartu'][$v->id]['router_id']);
    }

    public function test_wajib_habis_disabled_belum_habis(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'limit-uptime' => '1s', 'disabled' => 'true']]);
        $v = $this->kartu('abc');

        $this->assertSame('belum_habis', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_wajib_habis_db_aktif_router_habis_ikut_dicabut(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'limit-uptime' => '1s']]);
        $v = $this->kartu('abc', 'p', ['status' => 'active', 'expired_at' => now()->addDay()]);

        $this->assertSame('dicabut', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_wajib_habis_db_habis_router_belum_habis_ditahan(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']]);
        $v = $this->kartu('abc', 'p', ['status' => 'expired']);

        $this->assertSame('belum_habis', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_wajib_habis_db_habis_router_tidak_ada_menjadi_tidak_ada(): void
    {
        $this->mulai([['name' => 'lain', 'password' => 'p']]);
        $v = $this->kartu('abc', 'p', ['status' => 'expired']);
        $h = $this->kartu('abd', 'p', ['status' => 'missing']);

        $r = $this->nilai([$v, $h], true);

        $this->assertSame(['tidak_ada', 'tidak_ada'], [$this->hasil($r, $v), $this->hasil($r, $h)]);
    }

    public function test_wajib_habis_status_efektif_habis_router_tidak_ada_menjadi_tidak_ada(): void
    {
        $this->mulai([['name' => 'lain', 'password' => 'p']]);
        $v = $this->kartu('abc', 'p', ['status' => 'active', 'expired_at' => now()->subHour()]);

        $this->assertSame('active', $v->status);
        $this->assertSame('expired', $v->status_efektif);
        $this->assertSame('tidak_ada', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_wajib_habis_db_siap_router_tidak_ada_menjadi_tidak_di_router(): void
    {
        $this->mulai([['name' => 'lain', 'password' => 'p']]);
        $v = $this->kartu('abc');
        $a = $this->kartu('abd', 'p', ['status' => 'active', 'expired_at' => now()->addDay()]);

        $r = $this->nilai([$v, $a], true);

        $this->assertSame(['tidak_di_router', 'tidak_di_router'], [$this->hasil($r, $v), $this->hasil($r, $a)]);
    }

    public function test_tidak_wajib_habis_db_siap_router_tidak_ada_tetap_tidak_ada(): void
    {
        $this->mulai([['name' => 'lain', 'password' => 'p']]);
        $v = $this->kartu('abc');

        $this->assertSame('tidak_ada', $this->hasil($this->nilai([$v], false), $v));
    }

    public function test_wajib_habis_penanda_dicoba_membuat_tidak_ada(): void
    {
        $this->mulai([['name' => 'lain', 'password' => 'p']]);
        $v = $this->kartu('abc');
        Cache::put('pencabut.dicoba.' . $v->id, true, 86400);

        $this->assertSame('tidak_ada', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_jam_router_format_iso_terbaca(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'limit-uptime' => '1s']], [], ['date' => '2026-09-26', 'time' => '20:00:00', 'gmt-offset' => '+07:00']);
        $v = $this->kartu('abc');

        $this->assertSame('dicabut', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_jam_router_tak_terbaca_saat_wajib_habis_batal_total(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']], [], ['date' => 'rusak', 'time' => '20:00:00', 'gmt-offset' => '+07:00']);
        $v = $this->kartu('abc');

        $this->batal(fn () => $this->nilai([$v], true), 'Jam router tidak terbaca, hapus Habis dibatalkan.');
    }

    public function test_jam_router_tak_terbaca_tidak_dicek_bila_tidak_wajib_habis(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']], [], ['date' => 'rusak', 'time' => '20:00:00', 'gmt-offset' => '+07:00']);
        $v = $this->kartu('abc');

        $this->assertSame('dicabut', $this->hasil($this->nilai([$v], false), $v));
    }

    public function test_baris_router_tanpa_kunci_password_dianggap_kosong(): void
    {
        $this->mulai([['name' => 'abc'], ['name' => 'lain', 'password' => 'p']]);
        $v = $this->kartu('abc', '');

        $r = $this->nilai([$v]);

        $this->assertSame('dicabut', $this->hasil($r, $v));
        $this->assertSame('*10000', $r['per_kartu'][$v->id]['router_id']);
    }

    public static function contohComment(): array
    {
        return [
            'iso'            => ['2026-09-20 10:00:00'],
            'format lama'    => ['sep/20/2026 10:00:00'],
            'ekor tambahan'  => ['sep/20/2026 10:00:00 x'],
            'epoch'          => ['jan/01/1970 00:00:00'],
            'rusak'          => ['rusak'],
            'kosong'         => [''],
        ];
    }

    #[DataProvider('contohComment')]
    public function test_parser_comment_cocok_dan_parser_habis_sepakat(string $comment): void
    {
        $this->assertSame(
            app(VoucherLookupService::class)->keWaktu($comment) !== null,
            MikhmonRecord::waktu($comment) !== null,
        );

        $this->mulai([['name' => 'abc', 'password' => 'p', 'comment' => $comment]]);
        $v = $this->kartu('abc', 'p', ['comment' => 'vc-uji']);

        $cocokTanggal = MikhmonRecord::waktu($comment) !== null;

        $this->assertSame($cocokTanggal ? 'dicabut' : 'beda_batch', $this->hasil($this->nilai([$v]), $v));
    }

    public function test_wajib_habis_tanggal_sebelum_batch_dibuat_belum_habis(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'comment' => 'jan/01/2002 00:00:00', 'limit-uptime' => '']]);
        $v = $this->kartu('abc');

        $this->assertSame('belum_habis', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_wajib_habis_1s_dengan_tanggal_aneh_tetap_dicabut(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'comment' => 'jan/01/2002 00:00:00', 'limit-uptime' => '1s']]);
        $v = $this->kartu('abc');

        $this->assertSame('dicabut', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_wajib_habis_uptime_melebihi_jatah_dengan_tanggal_aneh_tetap_dicabut(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'comment' => 'jan/01/2002 00:00:00', 'uptime' => '2h', 'limit-uptime' => '1h']]);
        $v = $this->kartu('abc');

        $this->assertSame('dicabut', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_batch_impor_tanggal_sebelum_impor_tetap_habis(): void
    {
        $this->mulai(
            [
                ['name' => 'abc', 'password' => 'p', 'comment' => 'aug/20/2026 10:00:00'],
                ['name' => 'abd', 'password' => 'p', 'comment' => 'jan/01/2002 00:00:00'],
            ],
            [],
            null,
            ['source' => 'mikhmon_import'],
        );
        $this->batch->created_at = '2026-09-26 19:00:00';
        $this->batch->save();
        $a = $this->kartu('abc');
        $b = $this->kartu('abd');

        $r = $this->nilai([$a, $b], true);

        $this->assertSame('dicabut', $this->hasil($r, $a));
        $this->assertSame('belum_habis', $this->hasil($r, $b));
    }

    public function test_batch_impor_batas_wajar_tetap_2025_01_01(): void
    {
        $this->assertSame('2025-01-01 00:00:00', PencabutKartuService::BATAS_TANGGAL_WAJAR);
    }

    public function test_relasi_batch_sempit_dimuat_ulang(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'comment' => 'jan/01/2002 00:00:00']]);
        $this->kartu('abc');
        $v = Voucher::with('batch:id,validity')->where('username', 'abc')->sole();

        $this->assertSame('belum_habis', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_jam_router_selisih_lebih_10_menit_batal_total(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']], [], ['date' => 'sep/26/2026', 'time' => '20:11:00', 'gmt-offset' => '+07:00']);
        $v = $this->kartu('abc');

        $this->batal(fn () => $this->nilai([$v], true), 'Jam router selisih 11 menit dengan server, hapus Habis dibatalkan.');
    }

    public function test_jam_router_tertinggal_lebih_10_menit_juga_batal_total(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']], [], ['date' => 'sep/26/2026', 'time' => '19:48:30', 'gmt-offset' => '+07:00']);
        $v = $this->kartu('abc');

        $this->batal(fn () => $this->nilai([$v], true), 'Jam router selisih 12 menit dengan server, hapus Habis dibatalkan.');
    }

    public function test_jam_router_selisih_9_menit_tetap_jalan(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'limit-uptime' => '1s']], [], ['date' => 'sep/26/2026', 'time' => '20:09:00', 'gmt-offset' => '+07:00']);
        $v = $this->kartu('abc');

        $this->assertSame('dicabut', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_jam_router_tepat_10_menit_tetap_jalan(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'limit-uptime' => '1s']], [], ['date' => 'sep/26/2026', 'time' => '20:10:00', 'gmt-offset' => '+07:00']);
        $v = $this->kartu('abc');

        $this->assertSame('dicabut', $this->hasil($this->nilai([$v], true), $v));
    }

    public function test_jam_router_10_menit_1_detik_batal_total(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p', 'limit-uptime' => '1s']], [], ['date' => 'sep/26/2026', 'time' => '20:10:01', 'gmt-offset' => '+07:00']);
        $v = $this->kartu('abc');

        $this->batal(fn () => $this->nilai([$v], true), 'Jam router selisih 11 menit dengan server, hapus Habis dibatalkan.');
    }

    public function test_jam_router_tidak_dicek_bila_tidak_wajib_habis(): void
    {
        $this->mulai([['name' => 'abc', 'password' => 'p']], [], ['date' => 'jan/01/2002', 'time' => '00:00:00', 'gmt-offset' => '+07:00']);
        $v = $this->kartu('abc');

        $this->assertSame('dicabut', $this->hasil($this->nilai([$v], false), $v));
    }

    public function test_ringkasan_menghitung_per_hasil(): void
    {
        $this->mulai([
            ['name' => 'a', 'password' => 'p'],
            ['name' => 'b', 'password' => 'lain'],
            ['name' => 'c', 'password' => 'p', 'comment' => 'vc-lain'],
            ['name' => 'd', 'password' => 'p'],
            ['name' => 'd', 'password' => 'p'],
        ]);
        $kartu = [$this->kartu('a'), $this->kartu('b'), $this->kartu('c'), $this->kartu('d'), $this->kartu('e')];

        $r = $this->nilai($kartu);

        $this->assertSame([
            'dicabut' => 1, 'tidak_ada' => 1, 'ambigu' => 1, 'beda_password' => 1,
            'beda_batch' => 1, 'belum_habis' => 0, 'tidak_di_router' => 0, 'tidak_terbukti' => 0, 'gagal' => 0,
        ], $r['ringkasan']);
        $this->assertCount(5, $r['per_kartu']);
        $this->assertSame(array_keys($r['ringkasan']), PencabutKartuService::HASIL);
    }

    public function test_alasan_memuat_teks_untuk_setiap_hasil_ditahan(): void
    {
        $this->assertSame(['ambigu', 'beda_password', 'beda_batch', 'belum_habis', 'tidak_di_router', 'tidak_terbukti', 'gagal'], PencabutKartuService::DITAHAN);
        $this->assertSame([
            'ambigu'          => 'Ada lebih dari satu user cocok di router',
            'beda_password'   => 'Password di router beda dengan panel',
            'beda_batch'      => 'User router milik batch lain',
            'belum_habis'     => 'Di router belum habis',
            'tidak_di_router' => 'Tidak ada di router, padahal di panel belum habis',
            'tidak_terbukti'  => 'Tercatat hilang dari router, user bernama sama tidak terbukti miliknya',
            'gagal'           => 'Gagal dicabut dari router',
        ], PencabutKartuService::ALASAN);
    }

    public function test_nilai_tidak_menulis_apa_pun(): void
    {
        $this->mulai([
            ['name' => 'a', 'password' => 'p', 'limit-uptime' => '1s'],
            ['name' => 'b', 'password' => 'lain'],
            ['name' => 'c', 'password' => 'p', 'comment' => 'sep/26/2026 10:00:00'],
        ]);
        $kartu = [$this->kartu('a'), $this->kartu('b'), $this->kartu('c'), $this->kartu('d')];
        $dbSebelum     = Voucher::orderBy('id')->get()->toArray();
        $routerSebelum = ['users' => $this->palsu->users(), 'script' => $this->palsu->tabel('/system/script'), 'profil' => $this->palsu->tabel('/ip/hotspot/user/profile')];
        Carbon::setTestNow('2026-09-26 20:00:30');

        $this->nilai($kartu, true);
        $this->nilai($kartu, false);

        $hitung = $this->palsu->hitung();
        $this->assertArrayNotHasKey('remove', $hitung);
        $this->assertArrayNotHasKey('set', $hitung);
        $this->assertSame(0, $hitung['add']);
        $this->assertSame(2, $hitung['print']['/ip/hotspot/user']);
        $this->assertSame($routerSebelum, ['users' => $this->palsu->users(), 'script' => $this->palsu->tabel('/system/script'), 'profil' => $this->palsu->tabel('/ip/hotspot/user/profile')]);
        $this->assertSame($dbSebelum, Voucher::orderBy('id')->get()->toArray());
        $this->assertSame([], array_values(array_filter(array_keys($hitung), fn ($k) => in_array($k, ['remove', 'set', 'fetch'], true))));
    }
}
