<?php

namespace Tests\Feature;

use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\PencariKartuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class PencariKartuTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    private int $idRiwayat = 90000;

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

    private function router(string $slug, int $port = 1): Router
    {
        return Router::create(['slug' => $slug, 'name' => strtoupper($slug), 'host' => '127.0.0.1', 'port' => $port, 'username' => 'api', 'password' => 'rahasia']);
    }

    private function batch(string $code, Router $router): VoucherBatch
    {
        return VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => $router->name, 'code' => $code, 'profile' => '5-JAM', 'quantity' => 0, 'status' => 'success',
        ]);
    }

    private function hidup(VoucherBatch $batch, string $nama, string $password = 'p', ?int $nomor = null, array $isi = []): Voucher
    {
        return Voucher::create($isi + [
            'batch_id' => $batch->id, 'router_id' => $batch->router_id, 'nomor' => $nomor, 'username' => $nama, 'password' => $password,
            'profile' => '5-JAM', 'status' => 'ready', 'sync_status' => 'success',
        ]);
    }

    private function riwayat(?VoucherBatch $batch, string $nama, string $password = 'p', ?int $nomor = null, array $isi = []): DeletedVoucher
    {
        return DeletedVoucher::create($isi + [
            'voucher_id' => ++$this->idRiwayat, 'batch_id' => $batch?->id, 'batch_code' => $batch?->code, 'router_id' => $batch?->router_id,
            'router_name' => $batch?->router_name, 'nomor' => $nomor, 'username' => $nama, 'password' => $password, 'status' => 'expired',
            'hasil_router' => 'dicabut', 'cara' => 'massal', 'sumber' => 'panel', 'deleted_at' => now(),
        ]);
    }

    private function cari(array $ujung, ?string $batch = null, ?string $router = null): array
    {
        return app(PencariKartuService::class)->cari($ujung, $batch, $router);
    }

    public function test_username_persis_kartu_hidup(): void
    {
        $batch = $this->batch('vc-070-uji', $this->router('uji'));
        $kartu = $this->hidup($batch, '5K69994', '7777', 414);

        $h = $this->cari(['username' => '5K69994']);

        $this->assertSame('ketemu', $h['status']);
        $this->assertSame(['hidup', $kartu->id, 414, 'vc-070-uji', 'uji', 'UJI', 'Belum dipakai'], [
            $h['kartu']['sumber'], $h['kartu']['voucher_id'], $h['kartu']['nomor'], $h['kartu']['batch_code'],
            $h['kartu']['router_slug'], $h['kartu']['router_name'], $h['kartu']['status_label'],
        ]);
        $this->assertSame([], $h['peringatan']);
    }

    public function test_username_beda_huruf_ditemukan_dengan_peringatan(): void
    {
        $this->hidup($this->batch('vc-070-uji', $this->router('uji')), '5K69994', '7777', 414);

        $h = $this->cari(['username' => '5k69994']);

        $this->assertSame('ketemu', $h['status']);
        $this->assertSame(['Huruf beda: tercatat 5K69994. Login di router gagal kalau hurufnya tidak sama persis.'], $h['peringatan']);
    }

    public function test_kartu_terhapus_ditemukan_dari_riwayat(): void
    {
        $r = $this->riwayat($this->batch('vc-070-uji', $this->router('uji')), 'zz9', 'p', 5);

        $h = $this->cari(['username' => 'zz9']);

        $this->assertSame('ketemu', $h['status']);
        $this->assertSame(['terhapus', $r->voucher_id, 'expired', 'Habis', 5], [
            $h['kartu']['sumber'], $h['kartu']['voucher_id'], $h['kartu']['status'], $h['kartu']['status_label'], $h['kartu']['nomor'],
        ]);
    }

    public function test_kembar_lintas_batch_disaring_password_lalu_nomor(): void
    {
        $a  = $this->batch('vc-001-a', $this->router('uji'));
        $b  = $this->batch('vc-002-b', $this->router('uji2'));
        $this->hidup($a, 'zz', '1', 3);
        $kb = $this->hidup($b, 'zz', '2', 3);

        $this->assertSame('vc-002-b', $this->cari(['username' => 'zz', 'password' => '2'])['kartu']['batch_code']);

        $kb->update(['password' => '1', 'nomor' => 4]);

        $this->assertSame('ambigu', $this->cari(['username' => 'zz', 'password' => '1'])['status']);
        $this->assertSame('vc-002-b', $this->cari(['username' => 'zz', 'password' => '1', 'no' => 4])['kartu']['batch_code']);
    }

    public function test_tetap_ambigu_memberi_pilihan(): void
    {
        $a  = $this->batch('vc-001-a', $this->router('uji'));
        $b  = $this->batch('vc-002-b', $this->router('uji2'));
        $ka = $this->hidup($a, 'zz', '1', 3);
        $kb = $this->hidup($b, 'zz', '1', 3);

        $h = $this->cari(['username' => 'zz', 'password' => '1', 'no' => 3]);

        $this->assertSame('ambigu', $h['status']);
        $this->assertNull($h['kartu']);
        $this->assertEqualsCanonicalizing(['vc-001-a', 'vc-002-b'], array_column($h['pilihan'], 'batch_code'));
        $this->assertEqualsCanonicalizing([$ka->id, $kb->id], array_column($h['pilihan'], 'id'));
        $this->assertEqualsCanonicalizing(['uji', 'uji2'], array_column($h['pilihan'], 'router_slug'));
        $this->assertSame(['hidup', 'hidup'], array_column($h['pilihan'], 'sumber'));
    }

    public function test_no_dan_batch_tanpa_username(): void
    {
        $batch = $this->batch('vc-070-uji', $this->router('uji'));
        foreach ([1, 2, 3] as $n) {
            $this->hidup($batch, "r{$n}", 'p', $n);
        }

        $h = $this->cari(['no' => 2], 'vc-070-uji');

        $this->assertSame('ketemu', $h['status']);
        $this->assertSame('r2', $h['kartu']['username']);
        $this->assertSame([], $h['peringatan']);
    }

    public function test_no_di_batch_belum_bernomor_ditolak(): void
    {
        $batch = $this->batch('vc-070-uji', $this->router('uji'));
        $this->hidup($batch, 'r1');
        $this->hidup($batch, 'r2');

        $h = $this->cari(['no' => 2], 'vc-070-uji');
        $this->assertSame(['galat', 'Nomor kartu batch vc-070-uji belum dikunci. Cek lewat Username.'], [$h['status'], $h['pesan']]);

        $h = $this->cari(['username' => 'r2', 'no' => 2]);
        $this->assertSame(['galat', 'Nomor kartu batch vc-070-uji belum dikunci. Kosongkan isian No.'], [$h['status'], $h['pesan']]);

        $this->assertSame('ketemu', $this->cari(['username' => 'r2'])['status']);
    }

    public function test_no_beda_memberi_peringatan(): void
    {
        $this->hidup($this->batch('vc-070-uji', $this->router('uji')), '5K69994', '7777', 414);

        $h = $this->cari(['username' => '5K69994', 'no' => 413]);

        $this->assertSame('ketemu', $h['status']);
        $this->assertSame(['5K69994 tercatat No 414, Anda mengisi 413.'], $h['peringatan']);
    }

    public function test_password_beda_memberi_peringatan(): void
    {
        $this->hidup($this->batch('vc-070-uji', $this->router('uji')), '5K69994', '7777', 414);

        $h = $this->cari(['username' => '5K69994', 'password' => '1111']);

        $this->assertSame('ketemu', $h['status']);
        $this->assertSame(['Password beda dengan catatan panel.'], $h['peringatan']);
    }

    public function test_batch_yang_hanya_ada_di_riwayat_dikenali(): void
    {
        $batch = $this->batch('vc-050-uji', $this->router('uji'));
        $this->riwayat($batch, 'h1', 'p', 1);
        $this->riwayat($batch, 'h2', 'p', 2);
        $batch->delete();

        $h = $this->cari(['username' => 'h1'], 'vc-050-uji');
        $this->assertSame(['ketemu', 'terhapus', 'h1'], [$h['status'], $h['kartu']['sumber'], $h['kartu']['username']]);

        $h = $this->cari(['no' => 2], 'vc-050-uji');
        $this->assertSame(['ketemu', 'h2'], [$h['status'], $h['kartu']['username']]);
    }

    public function test_batch_tidak_dikenal_galat(): void
    {
        $this->hidup($this->batch('vc-070-uji', $this->router('uji')), 'r1');

        $h = $this->cari(['username' => 'r1'], 'vc-999-x');
        $this->assertSame(['galat', 'Batch vc-999-x tidak ditemukan di panel maupun riwayat.'], [$h['status'], $h['pesan']]);

        $h = $this->cari(['username' => 'r1'], 'VC-070-UJI');
        $this->assertSame('galat', $h['status']);

        $h = $this->cari(['username' => 'r1'], null, 'divx');
        $this->assertSame(['galat', 'Router divx tidak ditemukan.'], [$h['status'], $h['pesan']]);
    }

    public function test_tidak_ada(): void
    {
        $this->hidup($this->batch('vc-070-uji', $this->router('uji')), 'r1');

        $h = $this->cari(['username' => 'tidakada']);

        $this->assertSame(['tidak_ada', null, []], [$h['status'], $h['kartu'], $h['pilihan']]);
    }

    public function test_tanpa_username_wajib_no_dan_batch(): void
    {
        $batch = $this->batch('vc-070-uji', $this->router('uji'));
        $this->hidup($batch, 'r2', 'p', 2);

        foreach ([[['no' => 2], null], [[], 'vc-070-uji'], [['password' => 'p'], null]] as [$ujung, $kode]) {
            $h = $this->cari($ujung, $kode);
            $this->assertSame(['galat', 'Isi Username, atau No bersama Batch.'], [$h['status'], $h['pesan']]);
        }
    }

    public function test_saringan_router_dan_batch_dipakai(): void
    {
        $a = $this->batch('vc-001-a', $this->router('uji'));
        $b = $this->batch('vc-002-b', $this->router('uji2'));
        $this->hidup($a, 'zz', '1');
        $this->hidup($b, 'zz', '1');

        $this->assertSame('vc-002-b', $this->cari(['username' => 'zz'], null, 'uji2')['kartu']['batch_code']);
        $this->assertSame('vc-001-a', $this->cari(['username' => 'zz'], 'vc-001-a')['kartu']['batch_code']);
    }

    public function test_pilihan_menyelesaikan_kode_batch_kembar(): void
    {
        $this->palsu = RouterPalsu::mulai([], [], [
            '/ip/hotspot/user/profile' => [['name' => '4-JAM', 'on-login' => ':put (",ntfc,5000,1d,5000,,Disable,"); {}']],
        ]);
        $this->router('uji', $this->palsu->port);
        $this->actingAs(User::create([
            'name' => 'admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('sandi-admin'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));

        $this->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => '4-JAM', 'username' => 'tamu1'])->assertOk();
        $lama   = VoucherBatch::where('code', 'vc-tamu1')->sole();
        $idLama = Voucher::where('username', 'tamu1')->value('id');
        $this->deleteJson(route('vouchers.destroy', $lama), ['remove_from_router' => true, 'current_password' => 'sandi-admin'])->assertOk();
        $this->postJson(route('vouchers.cepat'), ['router' => 'uji', 'profile' => '4-JAM', 'username' => 'tamu1'])->assertOk();

        $baru = VoucherBatch::where('code', 'vc-tamu1')->sole();
        $this->assertNotSame($lama->id, $baru->id);
        $this->assertSame($lama->id, DeletedVoucher::where('voucher_id', $idLama)->value('batch_id'));

        $h = $this->cari(['username' => 'tamu1']);
        $this->assertSame('ambigu', $h['status']);
        $this->assertCount(2, $h['pilihan']);
        $this->assertSame('ambigu', $this->cari(['username' => 'tamu1'], 'vc-tamu1')['status']);

        $h = $this->cari(['username' => 'tamu1', 'kartu' => ['sumber' => 'terhapus', 'id' => $idLama]]);
        $this->assertSame(['ketemu', 'terhapus', $idLama], [$h['status'], $h['kartu']['sumber'], $h['kartu']['voucher_id']]);
    }

    public function test_pilihan_menyelesaikan_kartu_hidup_dan_riwayat_di_batch_yang_sama(): void
    {
        $batch = $this->batch('vc-070-uji', $this->router('uji'));
        $this->riwayat($batch, 'zz', '1');
        $hidup = $this->hidup($batch, 'zz', '1');

        $this->assertSame('ambigu', $this->cari(['username' => 'zz'])['status']);

        $h = $this->cari(['username' => 'zz', 'kartu' => ['sumber' => 'hidup', 'id' => $hidup->id]]);
        $this->assertSame(['ketemu', 'hidup', $hidup->id], [$h['status'], $h['kartu']['sumber'], $h['kartu']['voucher_id']]);
    }

    public function test_pilihan_kartu_yang_sudah_hilang_galat(): void
    {
        $this->hidup($this->batch('vc-070-uji', $this->router('uji')), 'r1');

        foreach (['hidup', 'terhapus'] as $sumber) {
            $h = $this->cari(['username' => 'r1', 'kartu' => ['sumber' => $sumber, 'id' => 999999]]);
            $this->assertSame(['galat', 'Kartu yang dipilih sudah tidak ada. Cek ulang.'], [$h['status'], $h['pesan']], $sumber);
        }
    }

    public function test_pilihan_tidak_cocok_dengan_username_galat(): void
    {
        $batch = $this->batch('vc-070-uji', $this->router('uji'));
        $r2    = $this->hidup($batch, 'r2', 'p', 2);
        $this->hidup($batch, 'r4', 'p', 4);

        $h = $this->cari(['username' => 'r4', 'kartu' => ['sumber' => 'hidup', 'id' => $r2->id]]);

        $this->assertSame(['galat', 'Kartu yang dipilih tidak cocok dengan Username. Cek ulang.'], [$h['status'], $h['pesan']]);
    }

    public function test_no_bertipe_string_diperlakukan_sama(): void
    {
        $batch = $this->batch('vc-070-uji', $this->router('uji'));
        $kartu = $this->hidup($batch, '5K69994', '7777', 414);

        $this->assertSame([], $this->cari(['username' => '5K69994', 'no' => '414'])['peringatan']);
        $this->assertSame('5K69994', $this->cari(['no' => '414'], 'vc-070-uji')['kartu']['username']);
        $this->assertSame('ketemu', $this->cari(['username' => '5K69994', 'kartu' => ['sumber' => 'hidup', 'id' => (string) $kartu->id]])['status']);

        $b = $this->batch('vc-071-uji', $this->router('uji2'));
        $this->hidup($b, '5K69994', '7777', 4);
        $this->assertSame('vc-071-uji', $this->cari(['username' => '5K69994', 'no' => '4'])['kartu']['batch_code']);
    }
}
