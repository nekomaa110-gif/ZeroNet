<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\VoucherRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class VoucherRouterCocokTest extends TestCase
{
    use RefreshDatabase;

    private ?RouterPalsu $palsu = null;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->palsu?->berhenti();
        parent::tearDown();
    }

    private function mulai(array $users, array $skenario = []): void
    {
        $this->palsu  = RouterPalsu::mulai($users, $skenario);
        $this->router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port, 'username' => 'api', 'password' => 'rahasia',
        ]);
    }

    private function cabut(iterable $kartu): array
    {
        return app(VoucherRouterService::class)->removeUsers($this->router, $kartu);
    }

    private function sisa(): array
    {
        return array_map(fn ($u) => $u['name'] . '/' . ($u['password'] ?? '-'), $this->palsu->users());
    }

    public function test_hanya_baris_dengan_nama_dan_password_persis_yang_dicabut(): void
    {
        $this->mulai([
            ['name' => 'zt1', 'password' => 'a'], ['name' => 'zt1', 'password' => 'b'],
            ['name' => 'ZT1', 'password' => 'a'], ['name' => 'zt2', 'password' => 'c'], ['name' => 'zt2', 'password' => 'c'],
        ]);

        $hasil = $this->cabut([['username' => 'zt1', 'password' => 'a'], ['username' => 'zt2', 'password' => 'c'], ['username' => 'zt3', 'password' => 'x']]);

        $this->assertSame(['zt1' => 'dicabut', 'zt2' => 'dicabut', 'zt3' => 'tidak_ada'], $hasil['hasil']);
        $this->assertSame([2, 1, 0, 0], [$hasil['removed'], $hasil['missing'], $hasil['beda_password'], $hasil['gagal']]);
        $this->assertSame(['zt1/b', 'ZT1/a'], $this->sisa());
    }

    public function test_nama_ada_tapi_password_beda_tidak_dicabut(): void
    {
        $this->mulai([['name' => 'zt1', 'password' => 'b']]);

        $hasil = $this->cabut([['username' => 'zt1', 'password' => 'a']]);

        $this->assertSame(['zt1' => 'beda_password'], $hasil['hasil']);
        $this->assertSame(1, $hasil['beda_password']);
        $this->assertSame(['zt1/b'], $this->sisa());
    }

    public function test_router_yang_tidak_memberi_password_membatalkan_tanpa_mencabut(): void
    {
        $this->mulai([['name' => 'zt1', 'password' => 'a']], ['sembunyikan_password' => true]);

        try {
            $this->cabut([['username' => 'zt1', 'password' => 'a']]);
            $this->fail('Seharusnya melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('Router tidak memberi password user hotspot, hapus dibatalkan.', $e->getMessage());
        }

        $this->assertSame(['zt1/a'], $this->sisa());
    }

    public function test_sebagian_baris_tanpa_field_password_dianggap_kosong(): void
    {
        $this->mulai([['name' => 'zt1'], ['name' => 'zt2', 'password' => 'c']]);

        $hasil = $this->cabut([['username' => 'zt1', 'password' => 'a'], ['username' => 'zt2', 'password' => 'c']]);

        $this->assertSame(['zt1' => 'beda_password', 'zt2' => 'dicabut'], $hasil['hasil']);
    }

    public function test_no_such_item_dihitung_tidak_ada_dan_trap_lain_dihitung_gagal(): void
    {
        $this->mulai(
            [['name' => 'zt1', 'password' => 'a'], ['name' => 'zt2', 'password' => 'b']],
            ['remove_hilang' => ['zt1'], 'remove_gagal' => ['zt2']],
        );

        $hasil = $this->cabut([['username' => 'zt1', 'password' => 'a'], ['username' => 'zt2', 'password' => 'b']]);

        $this->assertSame(['zt1' => 'tidak_ada', 'zt2' => 'gagal'], $hasil['hasil']);
        $this->assertSame([0, 1, 0, 1], [$hasil['removed'], $hasil['missing'], $hasil['beda_password'], $hasil['gagal']]);
        $this->assertSame(['zt2/b'], $this->sisa());
    }

    public function test_username_dobel_di_input_dihitung_sekali(): void
    {
        $this->mulai([['name' => 'zt1', 'password' => 'a']]);

        $hasil = $this->cabut([['username' => 'zt1', 'password' => 'a'], ['username' => 'zt1', 'password' => 'a']]);

        $this->assertSame(['zt1' => 'dicabut'], $hasil['hasil']);
        $this->assertSame(1, $hasil['removed']);
    }

    public function test_password_berisi_sama_dengan_tetap_cocok(): void
    {
        $this->mulai([['name' => 'zt1', 'password' => 'a=b c']]);

        $this->assertSame(['zt1' => 'dicabut'], $this->cabut([['username' => 'zt1', 'password' => 'a=b c']])['hasil']);
        $this->assertSame([], $this->sisa());
    }

    public function test_kembar_campuran_hanya_baris_cocok_yang_dicabut(): void
    {
        $this->mulai([['name' => 'zt1', 'password' => 'a'], ['name' => 'zt1']]);

        $this->assertSame(['zt1' => 'dicabut'], $this->cabut([['username' => 'zt1', 'password' => 'a']])['hasil']);
        $this->assertSame(['zt1/-'], $this->sisa());
    }

    public function test_router_kosong_semua_tidak_ada(): void
    {
        $this->mulai([]);

        $hasil = $this->cabut([['username' => 'zt1', 'password' => 'a']]);

        $this->assertSame(['zt1' => 'tidak_ada'], $hasil['hasil']);
        $this->assertSame(1, $hasil['missing']);
    }

    public function test_nama_huruf_besar_dan_nama_angka_tetap_cocok(): void
    {
        $this->mulai([['name' => '0X49079', 'password' => '4827'], ['name' => '48213', 'password' => '2345'], ['name' => '0x49079', 'password' => '4827']]);

        $hasil = $this->cabut([['username' => '0X49079', 'password' => '4827'], ['username' => '48213', 'password' => '2345']]);

        $this->assertSame(['0X49079' => 'dicabut', '48213' => 'dicabut'], $hasil['hasil']);
        $this->assertSame(['0x49079/4827'], $this->sisa());
    }

    public function test_hasil_tetap_milik_kartu_yang_benar_walau_jumlah_perintah_per_kartu_beda(): void
    {
        config(['voucher.sync_chunk' => 2]);
        $this->mulai([
            ['name' => 'zt1', 'password' => 'a'], ['name' => 'zt1', 'password' => 'a'],
            ['name' => 'zt2', 'password' => 'z'], ['name' => 'zt3', 'password' => 'c'], ['name' => 'zt4', 'password' => 'd'],
        ], ['remove_gagal' => ['zt3']]);

        $hasil = $this->cabut([
            ['username' => 'zt0', 'password' => 'x'], ['username' => 'zt1', 'password' => 'a'], ['username' => 'zt2', 'password' => 'b'],
            ['username' => 'zt3', 'password' => 'c'], ['username' => 'zt4', 'password' => 'd'],
        ]);

        $this->assertSame(['zt0' => 'tidak_ada', 'zt1' => 'dicabut', 'zt2' => 'beda_password', 'zt3' => 'gagal', 'zt4' => 'dicabut'], $hasil['hasil']);
        $this->assertSame([2, 1, 1, 1], [$hasil['removed'], $hasil['missing'], $hasil['beda_password'], $hasil['gagal']]);
        $this->assertSame(['zt2/z', 'zt3/c'], $this->sisa());
    }

    public function test_input_kosong_mengembalikan_bentuk_lengkap(): void
    {
        $this->mulai([['name' => 'zt1', 'password' => 'a']]);

        $this->assertSame(['removed' => 0, 'missing' => 0, 'beda_password' => 0, 'gagal' => 0, 'hasil' => []], $this->cabut([]));
        $this->assertSame(['zt1/a'], $this->sisa());
    }

    public function test_menerima_model_voucher(): void
    {
        $this->mulai([['name' => 'zt1', 'password' => 'a']]);
        $batch = VoucherBatch::create(['router_id' => $this->router->id, 'code' => 'up-1-uji', 'profile' => '5-JAM', 'quantity' => 1, 'status' => 'success']);
        $kartu = Voucher::create([
            'batch_id' => $batch->id, 'router_id' => $this->router->id, 'username' => 'zt1', 'password' => 'a', 'profile' => '5-JAM', 'sync_status' => 'success',
        ]);

        $this->assertSame(['zt1' => 'dicabut'], $this->cabut([$kartu])['hasil']);
        $this->assertSame([], $this->sisa());
    }
}
