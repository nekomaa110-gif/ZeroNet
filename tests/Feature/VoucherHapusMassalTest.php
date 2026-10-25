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

class VoucherHapusMassalTest extends TestCase
{
    use RefreshDatabase;

    private RouterPalsu $palsu;

    private VoucherBatch $batch;

    private VoucherBatch $lain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();

        $kartu = [];
        foreach (range(1, 55) as $i) {
            $kartu[sprintf('x%02d', $i)] = ['status' => 'expired'];
        }
        foreach (range(1, 5) as $i) {
            $kartu[sprintf('r%02d', $i)] = ['status' => 'ready'];
        }
        $kartu['a01']     = ['status' => 'active', 'expired_at' => now()->addHours(3)];
        $kartu['kembar1'] = ['status' => 'expired', 'sync_error' => VoucherService::BENTROK_PREFIX . ': nama sudah dipakai user lain'];

        $this->palsu = RouterPalsu::mulai(array_map(
            fn ($nama) => ['name' => $nama, 'password' => 'p', 'profile' => '4-JAM', 'comment' => $nama === 'b01' ? 'up-999-lain' : 'up-176-uji'],
            [...array_keys($kartu), 'b01'],
        ));

        $router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->batch = VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => 'UJI', 'code' => 'up-176-uji',
            'profile' => '4-JAM', 'quantity' => count($kartu), 'status' => 'success',
        ]);
        foreach ($kartu as $nama => $isi) {
            Voucher::create($isi + [
                'batch_id' => $this->batch->id, 'router_id' => $router->id, 'username' => $nama, 'password' => 'p',
                'comment' => 'up-176-uji', 'profile' => '4-JAM', 'sync_status' => 'success',
            ]);
        }

        $this->lain = VoucherBatch::create([
            'router_id' => $router->id, 'router_name' => 'UJI', 'code' => 'up-999-lain',
            'profile' => '4-JAM', 'quantity' => 1, 'status' => 'success',
        ]);
        Voucher::create([
            'batch_id' => $this->lain->id, 'router_id' => $router->id, 'username' => 'b01', 'password' => 'p',
            'comment' => 'up-999-lain', 'profile' => '4-JAM', 'status' => 'ready', 'sync_status' => 'success',
        ]);
    }

    protected function tearDown(): void
    {
        $this->palsu->berhenti();
        parent::tearDown();
    }

    private function pengguna(string $role): User
    {
        return User::create([
            'name' => $role, 'username' => "{$role}uji", 'email' => "{$role}@example.test", 'password' => Hash::make('sandi-admin'),
            'role' => $role, 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function id(string ...$nama): array
    {
        return Voucher::whereIn('username', $nama)->pluck('id')->all();
    }

    private function diRouter(): array
    {
        return array_column($this->palsu->users(), 'name');
    }

    public function test_hapus_pilihan_manual_dari_panel_dan_router_dengan_password(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01', 'r02')])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 2])
            ->assertSessionMissing('success')->assertSessionMissing('error');

        $this->assertSame([], $this->id('r01', 'r02'));
        $this->assertCount(1, $this->id('r03'));
        $this->assertNotContains('r01', $this->diRouter());
        $this->assertNotContains('r02', $this->diRouter());
        $this->assertContains('r03', $this->diRouter());
        $this->assertSame(60, $this->batch->fresh()->quantity);

        $log = ActivityLog::where('action', 'voucher_delete_many')->sole();
        $this->assertSame(['jumlah' => 2, 'mode' => 'pilihan', 'rincian' => ['ready' => 2]], array_intersect_key($log->properties, ['jumlah' => 1, 'mode' => 1, 'rincian' => 1]));
    }

    public function test_hapus_semua_sesuai_filter_lintas_halaman_tanpa_mencabut_kartu_bentrok_dari_router(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'semua' => true, 'status' => 'expired', 'jumlah' => 56])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 56]);

        $this->assertSame(0, $this->batch->vouchers()->status('expired')->count());
        $this->assertSame(6, $this->batch->vouchers()->count());
        $this->assertSame([], array_values(array_filter($this->diRouter(), fn ($n) => str_starts_with($n, 'x'))));
        $this->assertContains('kembar1', $this->diRouter());
        $this->assertContains('a01', $this->diRouter());

        $log = ActivityLog::where('action', 'voucher_delete_many')->sole();
        $this->assertSame(['mode' => 'filter', 'dilewati_bentrok' => 1], array_intersect_key($log->properties, ['mode' => 1, 'dilewati_bentrok' => 1]));
    }

    public function test_ditolak_tanpa_menghapus_bila_password_salah_jumlah_berubah_atau_kartu_batch_lain(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $rute = route('vouchers.destroy-many', $this->batch);

        $this->deleteJson($rute, ['password' => 'salah', 'ids' => $this->id('r01')])->assertStatus(422);
        $this->assertSame(1, ActivityLog::where('action', 'password_confirm_failed')->count());

        $this->deleteJson($rute, ['password' => 'sandi-admin', 'semua' => true, 'status' => 'expired', 'jumlah' => 55])
            ->assertStatus(409)->assertJsonPath('error', fn ($e) => str_contains($e, 'berubah'));

        $this->deleteJson($rute, ['password' => 'sandi-admin', 'ids' => $this->id('a01', 'b01')])->assertStatus(422);

        $this->assertSame(63, $this->batch->vouchers()->count() + $this->lain->vouchers()->count());
        $this->assertCount(63, $this->diRouter());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_delete_many')->count());
    }

    public function test_operator_ditolak_dan_batch_yang_sedang_sinkron_ditolak(): void
    {
        $this->actingAs($this->pengguna('operator'));
        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01')])->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->pengguna('admin'));
        $this->batch->update(['status' => 'syncing']);
        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01')])->assertStatus(409);

        $this->assertCount(1, $this->id('r01'));
    }

    public function test_router_gagal_tidak_ada_kartu_terhapus_dari_panel(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->palsu->skenario(['tolak_login' => true]);

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01', 'r02')])
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->assertCount(2, $this->id('r01', 'r02'));
        $this->assertSame(62, $this->batch->fresh()->quantity);
    }

    public function test_daftar_kartu_punya_centang_dan_info_filter_hanya_untuk_admin(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $isi = $this->get(route('vouchers.show', ['batch' => $this->batch, 'status' => 'expired']))->assertOk()->getContent();

        $this->assertStringContainsString('data-pilih-semua', $isi);
        $this->assertSame(50, substr_count($isi, 'data-pilih '));
        $this->assertMatchesRegularExpression('#data-daftar-info[^>]*data-total="56"#', $isi);
        $this->assertStringContainsString('data-rincian="{&quot;expired&quot;:56}"', $isi);

        $this->flushSession();
        $this->actingAs($this->pengguna('operator'));
        $this->get(route('vouchers.show', $this->batch))->assertOk()->assertDontSee('data-pilih-semua', false)->assertDontSee('data-daftar-info', false);
    }

    public function test_kartu_yang_gagal_dicabut_tetap_di_panel_dan_dilaporkan(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->palsu->skenario(['remove_gagal' => ['r01']]);

        $res = $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01', 'r02')])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 1, 'ditahan_lain' => 0]);

        $this->assertStringContainsString('1 kartu ditahan dan tetap ada di panel', $res->json('message'));
        $res->assertSessionHas('error', fn ($pesan) => str_contains($pesan, '1 kartu ditahan dan tetap ada di panel'));
        $this->assertSame(1, $res->json('ringkasan.gagal'));
        $this->assertSame(1, $res->json('ringkasan.dicabut'));
        $this->assertSame([['nomor' => null, 'username' => 'r01', 'alasan' => 'Gagal dicabut dari router']], $res->json('ditahan'));
        $this->assertCount(1, $this->id('r01'));
        $this->assertSame([], $this->id('r02'));
        $this->assertContains('r01', $this->diRouter());
        $this->assertNotContains('r02', $this->diRouter());
        $this->assertSame(61, $this->batch->fresh()->quantity);

        $log = ActivityLog::where('action', 'voucher_delete_many')->sole();
        $this->assertSame(
            ['jumlah' => 1, 'dicabut_router' => 1, 'dilewati_beda_password' => 0, 'gagal_cabut' => 1, 'ditahan' => ['gagal' => 1], 'wajib_habis' => false],
            array_intersect_key($log->properties, ['jumlah' => 1, 'dicabut_router' => 1, 'dilewati_beda_password' => 1, 'gagal_cabut' => 1, 'ditahan' => 1, 'wajib_habis' => 1]),
        );
        $this->assertSame(0, DeletedVoucher::where('username', 'r01')->count());
    }

    public function test_semua_kartu_gagal_dicabut_tidak_ada_yang_terhapus(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->palsu->skenario(['remove_gagal' => ['r01', 'r02']]);

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01', 'r02')])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'Semua kartu ditahan. 0 dicabut dari router, 0 sudah tidak ada di router, 0 dihapus dari panel saja, 2 ditahan.']);

        $this->assertCount(2, $this->id('r01', 'r02'));
        $this->assertSame(62, $this->batch->fresh()->quantity);
        $this->assertSame(0, ActivityLog::where('action', 'voucher_delete_many')->count());
    }

    public function test_kembaran_berpassword_lain_di_router_tidak_ikut_dicabut(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->palsu->tambah('/ip/hotspot/user', [
            ['name' => 'r02', 'password' => 'lain', 'profile' => '4-JAM', 'comment' => 'up-176-uji'],
            ['name' => 'r03', 'password' => 'lain', 'profile' => '4-JAM', 'comment' => 'up-176-uji'],
        ]);
        $this->palsu->hapusBaris('/ip/hotspot/user', 'r03', 'p');

        $res = $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r02', 'r03')])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 1]);

        $this->assertStringContainsString('1 kartu ditahan dan tetap ada di panel', $res->json('message'));
        $this->assertSame([], $this->id('r02'));
        $this->assertCount(1, $this->id('r03'));
        $sisa = array_values(array_map(
            fn ($u) => $u['name'] . '/' . $u['password'],
            array_filter($this->palsu->users(), fn ($u) => in_array($u['name'], ['r02', 'r03'], true)),
        ));
        $this->assertSame(['r02/lain', 'r03/lain'], $sisa);

        $log = ActivityLog::where('action', 'voucher_delete_many')->sole();
        $this->assertSame(1, $log->properties['dilewati_beda_password']);
        $this->assertSame(1, $log->properties['dicabut_router']);
    }

    public function test_hapus_kartu_yang_tidak_perlu_dicabut_tidak_menyentuh_router(): void
    {
        $this->actingAs($this->pengguna('admin'));

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('kembar1')])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 1]);

        $this->assertSame([], $this->id('kembar1'));
        $this->assertContains('kembar1', $this->diRouter());

        $log = ActivityLog::where('action', 'voucher_delete_many')->sole();
        $this->assertSame(
            ['dicabut_router' => 0, 'dilewati_beda_password' => 0, 'gagal_cabut' => 0],
            array_intersect_key($log->properties, ['dicabut_router' => 1, 'dilewati_beda_password' => 1, 'gagal_cabut' => 1]),
        );
    }

    public function test_router_tanpa_password_membatalkan_hapus_massal(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->palsu->skenario(['sembunyikan_password' => true]);

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01')])
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->assertCount(1, $this->id('r01'));
        $this->assertContains('r01', $this->diRouter());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_delete_many')->count());
    }

    private function pratinjau(array $isi = [], ?VoucherBatch $batch = null)
    {
        return $this->postJson(route('vouchers.preview-destroy-many', $batch ?? $this->batch), $isi);
    }

    private function jumlahRemove(): int
    {
        return $this->palsu->hitung()['remove']['/ip/hotspot/user'] ?? 0;
    }

    public function test_pratinjau_tidak_menulis_apa_pun(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $sebelum = Voucher::count();

        $res = $this->pratinjau(['ids' => $this->id('r01', 'r02')])->assertOk();

        $this->assertSame(
            ['success', 'kandidat', 'ringkasan', 'ditahan', 'ditahan_lain', 'pesan'],
            array_keys($res->json()),
        );
        $this->assertSame(2, $res->json('kandidat'));
        $this->assertSame(9, count($res->json('ringkasan')));
        $this->assertSame(2, $res->json('ringkasan.dicabut'));
        $this->assertSame([], $res->json('ditahan'));
        $this->assertSame(0, $res->json('ditahan_lain'));
        $this->assertSame('2 dicabut dari router, 0 sudah tidak ada di router, 0 dihapus dari panel saja, 0 ditahan.', $res->json('pesan'));

        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame($sebelum, Voucher::count());
        $this->assertSame(0, DeletedVoucher::count());
        $this->assertSame(0, ActivityLog::whereIn('action', ['voucher_delete_many', 'voucher_cabut_mulai'])->count());
        $this->assertSame(62, $this->batch->fresh()->quantity);
        $this->assertContains('r01', $this->diRouter());
    }

    public function test_pratinjau_tanpa_password_di_balasan(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->palsu->hapusBaris('/ip/hotspot/user', 'r01');
        $this->palsu->hapusBaris('/ip/hotspot/user', 'r02');
        $this->palsu->tambah('/ip/hotspot/user', [
            ['name' => 'r01', 'password' => 'rhs-router-71', 'profile' => '4-JAM', 'comment' => 'up-176-uji'],
            ['name' => 'r02', 'password' => 'rhs-router-72', 'profile' => '4-JAM', 'comment' => 'up-176-uji'],
        ]);
        Voucher::where('username', 'r01')->update(['password' => 'rhs-panel-81']);
        Voucher::where('username', 'r02')->update(['password' => 'rhs-router-72']);

        $isi = $this->pratinjau(['ids' => $this->id('r01', 'r02')])->assertOk()->getContent();

        $this->assertStringContainsString('"alasan":"Password di router beda dengan panel"', $isi);
        foreach (['rhs-router-71', 'rhs-router-72', 'rhs-panel-81', '"password"'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $isi);
        }
    }

    public function test_pratinjau_operator_403(): void
    {
        $this->actingAs($this->pengguna('operator'));

        $this->pratinjau(['ids' => $this->id('r01')])->assertForbidden();
        $this->assertSame(0, $this->palsu->hitung()['koneksi']);
    }

    public function test_pratinjau_saat_sinkron_409(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->batch->update(['status' => 'syncing']);

        $this->pratinjau(['ids' => $this->id('r01')])->assertStatus(409);
        $this->assertSame(0, $this->palsu->hitung()['koneksi']);
    }

    public function test_pratinjau_throttle_ke_21_ditolak(): void
    {
        $this->actingAs($this->pengguna('admin'));

        foreach (range(1, 20) as $i) {
            $this->pratinjau(['ids' => $this->id('r01')])->assertOk();
        }

        $this->pratinjau(['ids' => $this->id('r01')])
            ->assertStatus(429)
            ->assertExactJson(['success' => false, 'error' => 'Terlalu banyak pratinjau, tunggu sebentar.']);
    }

    public function test_pratinjau_ditahan_urut_nomor_lalu_username_dan_maksimal_100_baris(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $baris = [];
        $router = Router::first();
        foreach (range(1, 105) as $i) {
            $baris[] = [
                'batch_id' => $this->batch->id, 'router_id' => $router->id, 'username' => sprintf('t%03d', $i), 'password' => 'p',
                'comment' => 'up-176-uji', 'profile' => '4-JAM', 'status' => 'ready', 'sync_status' => 'success',
                'nomor' => 1000 - $i, 'created_at' => now(), 'updated_at' => now(),
            ];
            $this->palsu->tambah('/ip/hotspot/user', [['name' => sprintf('t%03d', $i), 'password' => 'lain', 'profile' => '4-JAM', 'comment' => 'up-176-uji']]);
        }
        Voucher::insert($baris);

        $res = $this->pratinjau(['semua' => true, 'q' => 't'])->assertOk();

        $this->assertSame(105, $res->json('kandidat'));
        $this->assertSame(105, $res->json('ringkasan.beda_password'));
        $this->assertCount(100, $res->json('ditahan'));
        $this->assertSame(5, $res->json('ditahan_lain'));
        $this->assertSame([895, 't105'], [$res->json('ditahan.0.nomor'), $res->json('ditahan.0.username')]);
        $this->assertSame([994, 't006'], [$res->json('ditahan.99.nomor'), $res->json('ditahan.99.username')]);
        $this->assertSame('0 dicabut dari router, 0 sudah tidak ada di router, 0 dihapus dari panel saja, 105 ditahan.', $res->json('pesan'));
    }

    public function test_hapus_massal_sebagian_ditahan_sisanya_terhapus(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->palsu->hapusBaris('/ip/hotspot/user', 'r02');
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'r02', 'password' => 'lain', 'profile' => '4-JAM', 'comment' => 'up-176-uji']]);

        $res = $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01', 'r02', 'r03')])
            ->assertOk()->assertJson(['success' => true, 'jumlah' => 2]);

        $this->assertSame(1, $res->json('ringkasan.beda_password'));
        $this->assertSame(2, $res->json('ringkasan.dicabut'));
        $this->assertSame([['nomor' => null, 'username' => 'r02', 'alasan' => 'Password di router beda dengan panel']], $res->json('ditahan'));
        $this->assertSame(0, $res->json('ditahan_lain'));
        $this->assertSame([], $this->id('r01', 'r03'));
        $this->assertCount(1, $this->id('r02'));
        $this->assertSame(['r02'], array_values(array_intersect($this->diRouter(), ['r01', 'r02', 'r03'])));
        $this->assertSame(['r01', 'r03'], DeletedVoucher::orderBy('username')->pluck('username')->all());
        $this->assertSame(['beda_password' => 1], ActivityLog::where('action', 'voucher_delete_many')->sole()->properties['ditahan']);
    }

    public function test_hapus_massal_semua_ditahan_422(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->palsu->hapusBaris('/ip/hotspot/user', 'r01');
        $this->palsu->tambah('/ip/hotspot/user', [['name' => 'r01', 'password' => 'lain', 'profile' => '4-JAM', 'comment' => 'up-176-uji']]);

        $res = $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01')])
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame('Semua kartu ditahan. 0 dicabut dari router, 0 sudah tidak ada di router, 0 dihapus dari panel saja, 1 ditahan.', $res->json('error'));
        $this->assertSame(1, $res->json('ringkasan.beda_password'));
        $this->assertSame('r01', $res->json('ditahan.0.username'));
        $this->assertCount(1, $this->id('r01'));
        $this->assertSame(0, DeletedVoucher::count());
        $this->assertSame(0, ActivityLog::where('action', 'voucher_delete_many')->count());
        $this->assertSame(0, $this->jumlahRemove());
    }

    public function test_hapus_massal_saat_kunci_router_dipegang_tidak_menghapus_apa_pun(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $this->assertTrue(Cache::lock('router.tulis.uji', 600)->get());

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'ids' => $this->id('r01', 'r02')])
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->assertCount(2, $this->id('r01', 'r02'));
        $this->assertSame(0, DeletedVoucher::count());
        $this->assertSame(0, $this->jumlahRemove());
        $this->assertSame(0, ActivityLog::whereIn('action', ['voucher_delete_many', 'voucher_cabut_mulai'])->count());
    }

    public function test_delete_sama_dua_kali_berurutan_tidak_dobel(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $isi = ['password' => 'sandi-admin', 'ids' => $this->id('r01', 'r02')];

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), $isi)->assertOk();
        $this->assertSame(2, $this->jumlahRemove());

        $kedua = $this->deleteJson(route('vouchers.destroy-many', $this->batch), $isi);
        $this->assertContains($kedua->status(), [409, 422]);

        $this->assertSame(2, $this->jumlahRemove());
        $this->assertSame(2, DeletedVoucher::count());
        $this->assertSame(1, ActivityLog::where('action', 'voucher_cabut_mulai')->count());
        $this->assertSame(1, ActivityLog::where('action', 'voucher_delete_many')->count());
        $this->assertSame(60, $this->batch->fresh()->quantity);
    }

    public function test_kandidat_lebih_1000_ditolak_dengan_pesan_persempit(): void
    {
        $this->actingAs($this->pengguna('admin'));
        $router = Router::first();
        $baris  = [];
        foreach (range(1, 1001) as $i) {
            $baris[] = [
                'batch_id' => $this->batch->id, 'router_id' => $router->id, 'username' => sprintf('z%04d', $i), 'password' => 'p',
                'comment' => 'up-176-uji', 'profile' => '4-JAM', 'status' => 'ready', 'sync_status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($baris, 200) as $potong) {
            Voucher::insert($potong);
        }
        $pesan = 'Maksimal 1000 kartu sekali hapus. Persempit dengan pencarian username.';

        $this->deleteJson(route('vouchers.destroy-many', $this->batch), ['password' => 'sandi-admin', 'semua' => true, 'q' => 'z', 'jumlah' => 1001])
            ->assertStatus(422)->assertJson(['success' => false, 'error' => $pesan]);
        $this->pratinjau(['semua' => true, 'q' => 'z'])->assertStatus(422)->assertJson(['success' => false, 'error' => $pesan]);

        $this->assertSame(1001, Voucher::where('username', 'like', 'z%')->count());
        $this->assertSame(0, DeletedVoucher::count());
    }
}
