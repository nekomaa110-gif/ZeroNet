<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\TemplateCetakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VoucherNomorTampilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function masuk(): void
    {
        $this->actingAs(User::create([
            'name' => 'admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('x'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));
    }

    private function batchBernomor(array $nomor, array $dicetak = []): VoucherBatch
    {
        $r = Router::create(['slug' => 'div-a', 'name' => 'DIV-A', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);
        $b = VoucherBatch::create([
            'router_id' => $r->id, 'router_name' => $r->name, 'code' => 'up-100-uji', 'profile' => '4-JAM',
            'quantity' => count($nomor), 'status' => 'success', 'validity' => '1d', 'price' => 5000,
        ]);

        foreach ($nomor as $i => $n) {
            $urut = $i + 1;
            Voucher::create([
                'batch_id' => $b->id, 'router_id' => $r->id, 'nomor' => $n, 'username' => "n{$urut}", 'password' => "p{$urut}",
                'profile' => '4-JAM', 'comment' => $b->code, 'price' => 5000, 'status' => 'ready', 'sync_status' => 'success',
                'printed_at' => in_array($n, $dicetak, true) ? now() : null,
            ]);
        }

        return $b;
    }

    private function render(VoucherBatch $batch, $vouchers): string
    {
        return implode('', app(TemplateCetakService::class)->render('<div>{{nomor}}-{{kode}}</div>', $vouchers, $batch)['kartu']);
    }

    public function test_cetak_sebagian_memakai_nomor_terkunci(): void
    {
        $batch = $this->batchBernomor([1, 2, 3], dicetak: [1]);

        $isi = $this->render($batch, $batch->vouchers()->whereNull('printed_at')->orderBy('id')->get());

        $this->assertStringContainsString('2-n2', $isi);
        $this->assertStringContainsString('3-n3', $isi);
        $this->assertStringNotContainsString('-n1', $isi);
        $this->assertStringNotContainsString('1-n2', $isi);
    }

    public function test_nomor_kosong_kembali_ke_penghitung_lembar(): void
    {
        $batch = $this->batchBernomor([null, null]);

        $isi = $this->render($batch, $batch->vouchers()->orderBy('id')->get());

        $this->assertStringContainsString('1-n1', $isi);
        $this->assertStringContainsString('2-n2', $isi);
    }

    public function test_teks_bantuan_nomor(): void
    {
        $this->assertSame('Nomor kartu dalam batch', TemplateCetakService::VARIABEL['nomor']);
    }

    public function test_csv_diawali_kolom_nomor(): void
    {
        $this->masuk();
        $batch = $this->batchBernomor([414]);

        $baris = explode("\n", trim($this->get(route('vouchers.export', $batch))->assertOk()->streamedContent()));

        $this->assertStringStartsWith('nomor,username,password', $baris[0]);
        $this->assertStringStartsWith('414,n1,p1', $baris[1]);
    }

    public function test_daftar_batch_memakai_nomor_terkunci(): void
    {
        $this->masuk();
        $batch = $this->batchBernomor([414]);

        $this->get(route('vouchers.show', $batch), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('<td class="ix">414</td>', false);
    }

    public function test_daftar_batch_tanpa_nomor_memakai_indeks_lama(): void
    {
        $this->masuk();
        $batch = $this->batchBernomor([null, null]);

        $this->get(route('vouchers.show', $batch), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSeeInOrder(['<td class="ix">1</td>', '<td class="ix">2</td>'], false);
    }
}
