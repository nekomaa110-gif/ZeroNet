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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherHapusBatchDaftarTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    private Router $router;

    private VoucherBatch $a;

    private VoucherBatch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();

        $this->palsu = RouterPalsu::mulai(array_map(
            fn ($nama) => ['name' => $nama, 'password' => 'p', 'profile' => '4-JAM', 'comment' => $nama === 'm1' ? 'up-102-uji-b' : 'up-101-uji-a'],
            ['k1', 'k2', 'k3', 'm1'],
        ));
        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->a = $this->batch('up-101-uji-a', [
            'k1' => ['status' => 'ready', 'sync_status' => 'success'],
            'k2' => ['status' => 'active', 'sync_status' => 'success', 'expired_at' => now()->addHours(3)],
            'k3' => ['status' => 'active', 'sync_status' => 'success', 'expired_at' => now()->subHour()],
        ]);
        $this->b = $this->batch('up-102-uji-b', [
            'm1' => ['status' => 'ready', 'sync_status' => 'failed'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        parent::tearDown();
    }

    private function batch(string $kode, array $kartu): VoucherBatch
    {
        $batch = VoucherBatch::create([
            'router_id' => $this->router->id, 'router_name' => 'UJI', 'code' => $kode,
            'profile' => '4-JAM', 'quantity' => count($kartu), 'status' => 'success',
        ]);
        foreach ($kartu as $nama => $isi) {
            Voucher::create($isi + [
                'batch_id' => $batch->id, 'router_id' => $this->router->id, 'username' => $nama, 'password' => 'p', 'profile' => '4-JAM', 'comment' => $kode,
            ]);
        }

        return $batch;
    }

    private function masuk(string $role = 'admin'): void
    {
        $this->actingAs(User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('sandi-admin'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));
    }

    private function diRouter(): array
    {
        return array_column($this->palsu->users(), 'name');
    }

    private static function centang(string $html, int $id): string
    {
        preg_match('#<input[^>]*data-pilih value="' . $id . '"[^>]*>#', $html, $m);

        return $m[0] ?? '';
    }

    public function test_hapus_batch_lewat_json_dengan_password_mencabut_kartu_dari_router(): void
    {
        $this->masuk();

        $this->deleteJson(route('vouchers.destroy', $this->a), ['remove_from_router' => true, 'current_password' => 'sandi-admin'])
            ->assertOk()->assertJson(['success' => true, 'dicabut' => 3, 'ditahan' => 0]);

        $this->assertNull($this->a->fresh());
        $this->assertSame(['m1'], $this->diRouter());
        $this->assertSame(1, ActivityLog::where('action', 'voucher_batch_delete')->count());
    }

    public function test_hapus_batch_lewat_json_dari_panel_saja_tanpa_password(): void
    {
        $this->masuk();

        $this->deleteJson(route('vouchers.destroy', $this->a))->assertOk()->assertJson(['success' => true, 'dicabut' => 0, 'ditahan' => 0]);

        $this->assertNull($this->a->fresh());
        $this->assertSame(['k1', 'k2', 'k3', 'm1'], $this->diRouter());
    }

    public function test_hapus_batch_lewat_json_ditolak_dengan_sebab_tanpa_menghapus_apa_pun(): void
    {
        $this->masuk();
        $rute = route('vouchers.destroy', $this->a);

        $this->deleteJson($rute, ['remove_from_router' => true, 'current_password' => 'salah'])
            ->assertStatus(422)->assertJson(['success' => false, 'sebab' => 'password']);
        $this->assertSame(1, ActivityLog::where('action', 'password_confirm_failed')->count());

        $this->b->update(['status' => 'syncing']);
        $this->deleteJson(route('vouchers.destroy', $this->b))->assertStatus(409)->assertJson(['success' => false, 'sebab' => 'sinkron']);

        $this->palsu->skenario(['tolak_login' => true]);
        $this->deleteJson($rute, ['remove_from_router' => true, 'current_password' => 'sandi-admin'])
            ->assertStatus(422)->assertJson(['success' => false, 'sebab' => 'router']);

        $this->assertNotNull($this->a->fresh());
        $this->assertNotNull($this->b->fresh());
        $this->assertSame(4, Voucher::count());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_batch_delete')->count());
    }

    public function test_daftar_batch_punya_centang_berisi_rincian_kartu_hanya_untuk_admin(): void
    {
        $this->b->update(['status' => 'syncing']);
        $this->masuk();

        foreach ([[], ['X-Requested-With' => 'XMLHttpRequest']] as $kepala) {
            $isi = $this->get(route('vouchers.index'), $kepala)->assertOk()->getContent();

            $this->assertStringContainsString('data-pilih-semua', $isi);
            $a = self::centang($isi, $this->a->id);
            $this->assertStringContainsString('data-kode="up-101-uji-a"', $a);
            $this->assertStringContainsString('data-router="UJI"', $a);
            $this->assertStringContainsString('data-kartu="3"', $a);
            $this->assertStringContainsString('data-siap="1"', $a);
            $this->assertStringContainsString('data-aktif="1"', $a);
            $this->assertStringContainsString('data-hapus="' . route('vouchers.destroy', $this->a) . '"', $a);
            $this->assertStringNotContainsString('disabled', $a);

            $b = self::centang($isi, $this->b->id);
            $this->assertStringContainsString('data-siap="0"', $b);
            $this->assertStringContainsString('disabled', $b);
        }

        $this->flushSession();
        $this->masuk('operator');
        $this->get(route('vouchers.index'))->assertOk()->assertSee('up-101-uji-a')->assertDontSee('data-pilih', false)->assertDontSee('data-massal', false);
    }

    public function test_halaman_yang_kosong_setelah_batch_dihapus_menampilkan_halaman_terakhir(): void
    {
        foreach (range(1, 14) as $i) {
            $this->batch(sprintf('up-2%02d-uji-isi', $i), []);
        }
        $this->masuk();

        $isi = $this->get(route('vouchers.index', ['page' => 3]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Belum ada batch voucher', $isi);
        $this->assertStringContainsString('up-101-uji-a', $isi);
        $this->assertStringNotContainsString('up-102-uji-b', $isi);
    }

    private function hapusDariRouter(?VoucherBatch $batch = null)
    {
        return $this->deleteJson(route('vouchers.destroy', $batch ?? $this->a), ['remove_from_router' => true, 'current_password' => 'sandi-admin']);
    }

    private function pratinjau(?VoucherBatch $batch = null)
    {
        return $this->postJson(route('vouchers.preview-destroy', $batch ?? $this->a), []);
    }

    private function jumlahRemove(): int
    {
        return $this->palsu->hitung()['remove']['/ip/hotspot/user'] ?? 0;
    }

    private function isiBanyak(VoucherBatch $batch, int $jumlah, string $status = 'pending'): void
    {
        $baris = [];
        foreach (range(1, $jumlah) as $i) {
            $baris[] = [
                'batch_id' => $batch->id, 'router_id' => $this->router->id, 'username' => sprintf('z%04d', $i), 'password' => 'p',
                'comment' => $batch->code, 'profile' => '4-JAM', 'status' => 'ready', 'sync_status' => $status,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($baris, 200) as $potong) {
            Voucher::insert($potong);
        }
        $batch->update(['quantity' => $batch->quantity + $jumlah]);
    }

    public function test_hapus_batch_json_sebagian_ditahan_membalas_200_dengan_ringkasan(): void
    {
        $this->masuk();
        $this->palsu->skenario(['remove_gagal' => ['k1']]);

        $res = $this->hapusDariRouter()->assertOk()->assertJson([
            'success' => true,
            'message' => 'Batch belum terhapus seluruhnya: 1 kartu ditahan.',
            'dicabut' => 2,
            'ditahan' => 1,
            'ringkasan' => ['dicabut' => 2, 'gagal' => 1],
            'daftar_ditahan' => [['nomor' => null, 'username' => 'k1', 'alasan' => 'Gagal dicabut dari router']],
        ]);

        $this->assertSame(['success', 'message', 'dicabut', 'ditahan', 'ringkasan', 'daftar_ditahan'], array_keys($res->json()));
        $this->assertNotNull($this->a->fresh());
        $this->assertSame(1, $this->a->fresh()->quantity);
        $this->assertSame(['k1'], $this->a->vouchers()->pluck('username')->all());
        $this->assertContains('k1', $this->diRouter());
        $this->assertNotContains('k2', $this->diRouter());
        $this->assertSame(['k2' => 'dicabut', 'k3' => 'dicabut'], DeletedVoucher::orderBy('username')->pluck('hasil_router', 'username')->all());
        $this->assertSame(['batch'], DeletedVoucher::distinct()->pluck('cara')->all());

        $log = ActivityLog::where('action', 'voucher_batch_delete')->sole();
        $this->assertTrue($log->properties['remove_from_router']);
        $this->assertSame(['gagal' => 1], $log->properties['ditahan']);
    }

    public function test_hapus_batch_remove_gagal_membuat_kartu_ditahan_batch_tetap_ada(): void
    {
        $this->masuk();
        $this->palsu->skenario(['remove_gagal' => ['k2']]);

        $this->deleteJson(route('vouchers.destroy', $this->a), ['remove_from_router' => true, 'current_password' => 'sandi-admin'])
            ->assertOk()->assertJson(['success' => true, 'dicabut' => 2, 'ditahan' => 1]);

        $this->assertNotNull(VoucherBatch::find($this->a->id));
        $this->assertSame(['k2'], Voucher::where('batch_id', $this->a->id)->pluck('username')->all());
        $this->assertContains('k2', $this->diRouter());
        $this->assertEqualsCanonicalizing(['k1', 'k3'], DeletedVoucher::pluck('username')->all());
    }

    public function test_log_hapus_batch_sebagian_mencatat_jumlah_per_hasil_dan_ditahan_per_alasan(): void
    {
        $this->masuk();
        $this->palsu->skenario(['remove_gagal' => ['k2']]);

        $this->deleteJson(route('vouchers.destroy', $this->a), ['remove_from_router' => true, 'current_password' => 'sandi-admin'])->assertOk();

        $p = ActivityLog::where('action', 'voucher_batch_delete')->sole()->properties;
        $this->assertTrue($p['remove_from_router']);
        $this->assertSame(2, $p['dicabut_router']);
        $this->assertSame(0, $p['tidak_ada_di_router']);
        $this->assertSame(['gagal' => 1], $p['ditahan']);
    }

    public function test_log_hapus_batch_penuh_mencatat_jumlah_dicabut(): void
    {
        $this->masuk();

        $this->deleteJson(route('vouchers.destroy', $this->a), ['remove_from_router' => true, 'current_password' => 'sandi-admin'])->assertOk();

        $p = ActivityLog::where('action', 'voucher_batch_delete')->sole()->properties;
        $this->assertSame(3, $p['dicabut_router']);
        $this->assertSame(0, $p['tidak_ada_di_router']);
        $this->assertSame([], $p['ditahan']);
    }

    public function test_hapus_batch_tanpa_ditahan_alur_lama_batch_hilang(): void
    {
        $this->masuk();

        $res = $this->hapusDariRouter()->assertOk();

        $this->assertSame(['success', 'message', 'dicabut', 'ditahan'], array_keys($res->json()));
        $this->assertSame('Batch up-101-uji-a dihapus dari panel. 3 kartu ikut dihapus dari router.', $res->json('message'));
        $this->assertSame(0, $res->json('ditahan'));
        $this->assertNull($this->a->fresh());
        $this->assertSame(3, DeletedVoucher::where('batch_code', 'up-101-uji-a')->count());
        $this->assertSame(['dicabut'], DeletedVoucher::distinct()->pluck('hasil_router')->all());
        $this->assertSame(['batch'], DeletedVoucher::distinct()->pluck('cara')->all());
        $this->assertSame(['m1'], $this->diRouter());
        $this->assertSame(
            ['remove_from_router' => true, 'dicabut_router' => 3, 'tidak_ada_di_router' => 0, 'ditahan' => []],
            ActivityLog::where('action', 'voucher_batch_delete')->sole()->properties,
        );
    }

    public function test_hapus_batch_non_json_sebagian_ditahan_redirect_back_dengan_error(): void
    {
        $this->masuk();
        $this->palsu->skenario(['remove_gagal' => ['k1']]);
        $asal = route('vouchers.show', $this->a);

        $this->from($asal)->delete(route('vouchers.destroy', $this->a), ['remove_from_router' => 1, 'current_password' => 'sandi-admin'])
            ->assertRedirect($asal)
            ->assertSessionHas('error', 'Batch belum terhapus seluruhnya: 1 kartu ditahan.');

        $this->assertNotNull($this->a->fresh());
        $this->assertSame(1, $this->a->vouchers()->count());
    }

    public function test_hapus_batch_kartu_ragu_tidak_ikut_terhapus_dan_batas_1000_tidak_berlaku(): void
    {
        $this->masuk();
        $this->isiBanyak($this->a, 1100);
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'k2', 'password' => 'p', 'profile' => '4-JAM', 'comment' => 'up-101-uji-a']]);

        $this->hapusDariRouter()->assertOk()->assertJson([
            'success' => true, 'ditahan' => 1, 'dicabut' => 2,
            'daftar_ditahan' => [['username' => 'k2', 'alasan' => 'Ada lebih dari satu user cocok di router']],
        ]);

        $this->assertSame(['k2'], $this->a->vouchers()->pluck('username')->all());
        $this->assertSame(1, $this->a->fresh()->quantity);
        $this->assertSame(1102, DeletedVoucher::count());
        $this->assertSame(1100, DeletedVoucher::where('hasil_router', 'tidak_dicabut')->count());
    }

    public function test_hapus_batch_panel_saja_tidak_membaca_router(): void
    {
        $this->masuk();

        $this->deleteJson(route('vouchers.destroy', $this->a))->assertOk()->assertJson(['success' => true, 'ditahan' => 0]);

        $hitung = $this->palsu->hitung();
        $this->assertSame(0, $hitung['koneksi']);
        $this->assertSame(0, $hitung['print']['/ip/hotspot/user'] ?? 0);
        $this->assertSame(0, $hitung['hitung']['/ip/hotspot/user'] ?? 0);
        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame(['tidak_dicabut'], DeletedVoucher::distinct()->pluck('hasil_router')->all());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_cabut_mulai')->count());
    }

    public function test_hapus_batch_koneksi_putus_tidak_menghapus_apa_pun(): void
    {
        $this->masuk();
        $this->palsu->skenario(['putus_setelah_remove' => 1]);

        $res = $this->hapusDariRouter()->assertStatus(422)->assertJson(['success' => false, 'sebab' => 'router']);

        $this->assertStringContainsString('Koneksi ke router putus saat mencabut', $res->json('error'));
        $this->assertNotNull($this->a->fresh());
        $this->assertSame(3, $this->a->vouchers()->count());
        $this->assertSame(3, $this->a->fresh()->quantity);
        $this->assertSame(0, DeletedVoucher::count());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_batch_delete')->count());
        $this->assertSame(1, ActivityLog::where('action', 'voucher_cabut_mulai')->count());
    }

    public function test_hapus_batch_diulang_sesudah_kembar_dibereskan_batch_hilang(): void
    {
        $this->masuk();
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'k1', 'password' => 'p', 'profile' => '4-JAM', 'comment' => 'up-101-uji-a']]);

        $this->hapusDariRouter()->assertOk()->assertJson(['ditahan' => 1, 'ringkasan' => ['ambigu' => 1]]);

        $this->assertSame(1, $this->a->fresh()->quantity);
        $this->assertSame(['k1'], $this->a->vouchers()->pluck('username')->all());

        $this->palsu->hapusBaris('/ip/hotspot/user', 'k1');
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'k1', 'password' => 'p', 'profile' => '4-JAM', 'comment' => 'up-101-uji-a']]);

        $this->hapusDariRouter()->assertOk()->assertJson(['success' => true, 'ditahan' => 0, 'dicabut' => 1]);

        $this->assertNull($this->a->fresh());
        $this->assertSame(3, DeletedVoucher::count());
        $this->assertSame(3, DeletedVoucher::distinct()->count('voucher_id'));
        $this->assertSame(['m1'], $this->diRouter());
    }

    public function test_pratinjau_batch_tidak_menulis_apa_pun(): void
    {
        $this->masuk();
        $sebelum = Voucher::count();

        $res = $this->pratinjau()->assertOk();

        $this->assertSame(['success', 'kandidat', 'ringkasan', 'ditahan', 'ditahan_lain', 'pesan'], array_keys($res->json()));
        $this->assertSame(3, $res->json('kandidat'));
        $this->assertSame(9, count($res->json('ringkasan')));
        $this->assertSame(3, $res->json('ringkasan.dicabut'));
        $this->assertSame([], $res->json('ditahan'));
        $this->assertSame(0, $res->json('ditahan_lain'));
        $this->assertSame('3 dicabut dari router, 0 sudah tidak ada di router, 0 dihapus dari panel saja, 0 ditahan.', $res->json('pesan'));

        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame($sebelum, Voucher::count());
        $this->assertSame(0, DeletedVoucher::count());
        $this->assertSame(0, ActivityLog::whereIn('action', ['voucher_batch_delete', 'voucher_cabut_mulai'])->count());
        $this->assertSame(3, $this->a->fresh()->quantity);
        $this->assertSame(['k1', 'k2', 'k3', 'm1'], $this->diRouter());
    }

    public function test_pratinjau_batch_menghitung_panel_saja_dan_ditahan(): void
    {
        $this->masuk();
        $this->a->vouchers()->where('username', 'k1')->update(['sync_status' => 'pending']);
        $this->a->vouchers()->where('username', 'k2')->update(['sync_error' => VoucherService::BENTROK_PREFIX . ': nama sudah dipakai user lain']);
        $this->palsu->hapusBaris('/ip/hotspot/user', 'k3');
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'k3', 'password' => 'lain', 'profile' => '4-JAM', 'comment' => 'up-101-uji-a']]);

        $res = $this->pratinjau()->assertOk();

        $this->assertSame(3, $res->json('kandidat'));
        $this->assertSame(1, $res->json('ringkasan.beda_password'));
        $this->assertSame([['nomor' => null, 'username' => 'k3', 'alasan' => 'Password di router beda dengan panel']], $res->json('ditahan'));
        $this->assertSame('0 dicabut dari router, 0 sudah tidak ada di router, 2 dihapus dari panel saja, 1 ditahan.', $res->json('pesan'));
        $this->assertSame(3, $this->a->vouchers()->count());
        $this->assertSame(0, DeletedVoucher::count());
    }

    public function test_pratinjau_batch_tanpa_password_di_balasan(): void
    {
        $this->masuk();
        $this->palsu->hapusBaris('/ip/hotspot/user', 'k1');
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'k1', 'password' => 'rhs-router-71', 'profile' => '4-JAM', 'comment' => 'up-101-uji-a']]);
        $this->a->vouchers()->where('username', 'k1')->update(['password' => 'rhs-panel-81']);

        $isi = $this->pratinjau()->assertOk()->getContent();

        $this->assertStringContainsString('"alasan":"Password di router beda dengan panel"', $isi);
        foreach (['rhs-router-71', 'rhs-panel-81', '"password"'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $isi);
        }
    }

    public function test_pratinjau_batch_operator_403(): void
    {
        $this->masuk('operator');

        $this->pratinjau()->assertForbidden();

        $this->assertSame(0, $this->palsu->hitung()['koneksi']);
    }

    public function test_pratinjau_batch_saat_sinkron_409(): void
    {
        $this->masuk();
        $this->a->update(['status' => 'syncing']);

        $this->pratinjau()->assertStatus(409)->assertJson(['success' => false]);

        $this->assertSame(0, $this->palsu->hitung()['koneksi']);
    }

    public function test_pratinjau_batch_tanpa_router_semua_panel_saja_tanpa_membaca_router(): void
    {
        $this->masuk();
        $this->a->forceFill(['router_id' => null])->save();

        $res = $this->pratinjau()->assertOk();

        $this->assertSame(3, $res->json('kandidat'));
        $this->assertSame([], $res->json('ditahan'));
        $this->assertSame('0 dicabut dari router, 0 sudah tidak ada di router, 3 dihapus dari panel saja, 0 ditahan.', $res->json('pesan'));
        $this->assertSame(0, $this->palsu->hitung()['koneksi']);
    }

    public function test_pratinjau_batch_router_mati_422_tanpa_menulis(): void
    {
        $this->masuk();
        $this->palsu->skenario(['tolak_login' => true]);

        $this->pratinjau()->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame(3, $this->a->vouchers()->count());
        $this->assertSame(0, DeletedVoucher::count());
    }

    public function test_pratinjau_batch_lebih_1000_kartu_tidak_ditolak(): void
    {
        $this->masuk();
        $this->isiBanyak($this->a, 1100);

        $res = $this->pratinjau()->assertOk();

        $this->assertSame(1103, $res->json('kandidat'));
        $this->assertSame(3, $res->json('ringkasan.dicabut'));
        $this->assertStringContainsString('1100 dihapus dari panel saja', $res->json('pesan'));
    }
}
