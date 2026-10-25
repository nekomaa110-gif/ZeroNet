<?php

namespace Tests\Feature;

use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VoucherRiwayatSkemaTest extends TestCase
{
    use RefreshDatabase;

    private function riwayat(array $ubah = []): array
    {
        return $ubah + [
            'voucher_id' => 1, 'username' => 'zt1', 'password' => 'p', 'status' => 'expired',
            'hasil_router' => 'tidak_dicabut', 'cara' => 'satu', 'sumber' => 'panel', 'deleted_at' => '2026-10-15 21:00:00',
        ];
    }

    private function indexTabel(string $tabel): array
    {
        return collect(DB::select("SHOW INDEX FROM {$tabel}"))
            ->groupBy('Key_name')
            ->map(fn ($r) => $r->sortBy('Seq_in_index')->pluck('Column_name')->values()->all())
            ->values()
            ->all();
    }

    public function test_deleted_at_datetime_wajib_tanpa_on_update(): void
    {
        $k = DB::selectOne("SELECT DATA_TYPE, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deleted_vouchers' AND COLUMN_NAME = 'deleted_at'");

        $this->assertNotNull($k);
        $this->assertSame('datetime', $k->DATA_TYPE);
        $this->assertSame('NO', $k->IS_NULLABLE);
        $this->assertStringNotContainsStringIgnoringCase('on update', (string) $k->EXTRA);
    }

    public function test_deleted_at_tidak_berubah_saat_baris_diupdate(): void
    {
        $r = DeletedVoucher::create($this->riwayat(['deleted_at' => '2026-10-15 21:00:00']));

        $this->travel(5)->minutes();
        $r->update(['hasil_router' => 'tidak_diketahui']);

        $this->assertSame('tidak_diketahui', DB::table('deleted_vouchers')->where('id', $r->id)->value('hasil_router'));
        $this->assertSame('2026-10-15 21:00:00', DB::table('deleted_vouchers')->where('id', $r->id)->value('deleted_at'));
    }

    public function test_voucher_id_unik(): void
    {
        DeletedVoucher::create($this->riwayat(['voucher_id' => 9]));

        $this->expectException(QueryException::class);
        DeletedVoucher::create($this->riwayat(['voucher_id' => 9]));
    }

    public function test_vouchers_punya_nomor_dan_index_batch_nomor(): void
    {
        $this->assertTrue(Schema::hasColumn('vouchers', 'nomor'));
        $this->assertContains(['batch_id', 'nomor'], $this->indexTabel('vouchers'));
    }

    public function test_index_riwayat_terpasang(): void
    {
        $index = $this->indexTabel('deleted_vouchers');

        $this->assertContains(['router_id', 'username'], $index);
        $this->assertContains(['batch_id', 'nomor'], $index);
        $this->assertContains(['voucher_id'], $index);
        $this->assertContains(['deleted_at'], $index);
    }

    public function test_voucher_menyimpan_nomor_sebagai_angka(): void
    {
        $router = Router::create(['slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'api', 'password' => 'rahasia']);
        $batch  = VoucherBatch::create(['router_id' => $router->id, 'code' => 'vc-001-uji', 'profile' => '5-JAM', 'quantity' => 1, 'status' => 'success']);

        $kartu = Voucher::create([
            'batch_id' => $batch->id, 'router_id' => $router->id, 'username' => 'n1', 'password' => 'p', 'profile' => '5-JAM', 'nomor' => '414',
        ]);

        $this->assertSame(414, $kartu->fresh()->nomor);
    }

    public function test_label_riwayat(): void
    {
        $r = new DeletedVoucher(['status' => 'expired', 'cara' => 'massal', 'hasil_router' => 'beda_password']);

        $this->assertSame(['Habis', 'Hapus massal', 'Tidak dicabut, password di router beda'], [$r->status_label, $r->cara_label, $r->hasil_router_label]);

        $peta = [
            'dicabut' => 'Dicabut dari router', 'tidak_ada' => 'Sudah tidak ada di router', 'gagal' => 'Gagal dicabut',
            'tidak_dicabut' => 'Tidak dicabut dari router', 'tidak_diketahui' => 'Hasil cabut tidak diketahui',
        ];
        foreach ($peta as $kode => $label) {
            $this->assertSame($label, (new DeletedVoucher(['hasil_router' => $kode]))->hasil_router_label, $kode);
        }

        $this->assertSame(['Hapus satu kartu', 'Hapus batch'], [(new DeletedVoucher(['cara' => 'satu']))->cara_label, (new DeletedVoucher(['cara' => 'batch']))->cara_label]);
    }
}
