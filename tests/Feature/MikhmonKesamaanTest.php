<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Models\VoucherSale;
use App\Services\VoucherCodeGenerator;
use App\Services\VoucherRouterService;
use App\Services\VoucherScriptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RouterPalsu;
use Tests\TestCase;

class MikhmonKesamaanTest extends TestCase
{
    use RefreshDatabase;

    private const LOCK_MIKHMON = '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]';

    private ?RouterPalsu $palsu = null;

    protected function tearDown(): void
    {
        $this->palsu?->berhenti();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('x'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_charset_sama_dengan_fungsi_acak_mikhmon(): void
    {
        $this->assertSame([
            'lower'  => 'abcdefghijkmnprstuvwxyz',
            'upper'  => 'ABCDEFGHJKLMNPRSTUVWXYZ',
            'upplow' => 'ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz',
            'num'    => '23456789',
            'mix'    => '23456789abcdefghijkmnprstuvwxyz',
            'mix1'   => '23456789ABCDEFGHJKLMNPRSTUVWXYZ',
            'mix2'   => '23456789ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz',
        ], array_map(fn ($c) => $c['chars'], config('voucher.charsets')));
    }

    public function test_kode_vc_huruf_diakhiri_angka_seperti_mikhmon(): void
    {
        $gen = app(VoucherCodeGenerator::class);

        foreach ([3 => 1, 4 => 2, 5 => 2, 6 => 3, 7 => 3, 8 => 4] as $panjang => $ekor) {
            $huruf = $panjang - $ekor;
            foreach ($gen->make(20, 'lower', $panjang, 'vc', prefix: '7D') as $v) {
                $this->assertMatchesRegularExpression("/^7D[abcdefghijkmnprstuvwxyz]{{$huruf}}[2-9]{{$ekor}}$/", $v['username']);
                $this->assertSame($v['username'], $v['password']);
            }
        }

        foreach ($gen->make(20, 'mix', 5, 'vc') as $v) {
            $this->assertMatchesRegularExpression('/^[23456789abcdefghijkmnprstuvwxyz]{5}$/', $v['username']);
        }

        foreach ($gen->make(20, 'lower', 4, 'up', 4, '5K') as $v) {
            $this->assertMatchesRegularExpression('/^5K[abcdefghijkmnprstuvwxyz]{4}$/', $v['username']);
            $this->assertMatchesRegularExpression('/^[2-9]{4}$/', $v['password']);
        }

        $this->assertMatchesRegularExpression('/^up-\d{3}-\d{2}\.\d{2}\.\d{2}-stok5$/', $gen->batchCode('up', 'stok5'));
    }

    public function test_meta_on_login_sama_dengan_mikhmon_termasuk_profil_tanpa_masa_aktif(): void
    {
        $b = app(VoucherScriptBuilder::class);

        $ntfc = $b->profileOnLogin('5-JAM', ['expmode' => 'ntfc', 'price' => 5000, 'validity' => '1d', 'sprice' => 6000, 'lock' => true, 'record' => true], 'zeronet-onlogin');
        $this->assertStringStartsWith(':put (",ntfc,5000,1d,6000,,Enable,")', $ntfc);

        $mati = $b->profileOnLogin('Bebas', ['expmode' => 'off', 'price' => 3000, 'validity' => '', 'sprice' => 0, 'lock' => true, 'record' => false], 'zeronet-onlogin');
        $this->assertSame(':put (",,3000,,,noexp,Enable,")' . self::LOCK_MIKHMON, $mati);
        $this->assertSame(
            ['expmode' => '', 'record' => false, 'price' => 3000, 'validity' => '', 'sprice' => 0, 'lock' => true],
            VoucherRouterService::parseOnLogin($mati),
        );

        $tanpaKunci = $b->profileOnLogin('Bebas', ['expmode' => 'off', 'price' => 0, 'lock' => false], 'zeronet-onlogin');
        $this->assertSame(':put (",,0,,,noexp,Disable,")', $tanpaKunci);
    }

    public function test_kunci_mac_dan_record_hanya_saat_login_pertama_seperti_mikhmon(): void
    {
        $b = app(VoucherScriptBuilder::class);

        foreach (['6.49.19', '7.20.8'] as $versi) {
            $s = $b->loginScript($versi);
            $blok = substr($s, strpos($s, ':if ($pre = "vc" or $pre = "up" or $cmt = "") do={'));

            $this->assertStringContainsString('/ip hotspot user set $uid mac-address=$mac', $blok);
            $this->assertStringEndsWith("      /ip hotspot user set \$uid mac-address=\$mac\n    }\n  }\n}", $s);
            $this->assertStringContainsString('-|-$time-|-$user-|-$price-|-$address-|-$mac-|-$validity-|-$profile-|-$cmt" owner="$mon$yir"', $s);
            $this->assertStringContainsString('comment="mikhmon"', $s);
            $this->assertStringContainsString("/system scheduler remove [/system scheduler find where name=\"zn-\$user\"]\n      /system scheduler add name=\"zn-\$user\"", $s);
        }

        $this->assertStringContainsString('source="$date"', $b->loginScript('6.49.19'));
        $this->assertStringContainsString('source="$rdate"', $b->loginScript('7.20.8'));
    }

    public function test_pasang_script_mempertahankan_harga_jual_profil(): void
    {
        $this->palsu = RouterPalsu::mulai([], [], [
            '/ip/hotspot/user/profile' => [
                ['name' => 'default', 'on-login' => ''],
                ['name' => '5-JAM', 'on-login' => ':put (",ntfc,5000,1d,6000,,Disable,"); {}', 'shared-users' => '1'],
            ],
            '/system/scheduler' => [],
        ]);
        $router = Router::create([
            'slug' => 'uji', 'name' => 'UJI', 'host' => '127.0.0.1', 'port' => $this->palsu->port,
            'username' => 'api', 'password' => 'rahasia',
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('voucher-scripts.install', $router), ['profiles' => [
                ['name' => '5-JAM', 'mode' => 'ntf', 'record' => true, 'lock' => false, 'validity' => '1d', 'price' => 5000, 'sprice' => 6000],
            ]])
            ->assertOk()
            ->assertJson(['success' => true]);

        $onLogin = array_column($this->palsu->tabel('/ip/hotspot/user/profile'), 'on-login', 'name')['5-JAM'];
        $this->assertStringStartsWith(':put (",ntfc,5000,1d,6000,,Disable,")', $onLogin);
    }

    public function test_laporan_bisa_disaring_prefix_dan_diunduh_csv_seperti_mikhmon(): void
    {
        $r = Router::create(['slug' => 'div-a', 'name' => 'DIV-A', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);
        foreach ([['5Kab2', 5000], ['7Dxy9', 55000], ['=cmd', 1000]] as $i => [$user, $harga]) {
            VoucherSale::create([
                'router_id' => $r->id, 'router_name' => $r->name, 'record_hash' => sha1($user), 'record_name' => $user,
                'sold_at' => today()->setTime(9, $i), 'username' => $user, 'profile' => '5-JAM', 'price' => $harga,
                'batch_comment' => 'up-101-09.27.26-stok', 'mac' => 'AA:BB:CC:00:00:0' . $i,
            ]);
        }
        $this->actingAs($this->admin());

        $this->get(route('penjualan.index', ['prefix' => '5K']))
            ->assertOk()->assertSee('5Kab2')->assertDontSee('7Dxy9')->assertSee('Rp 5.000');

        $csv = $this->get(route('penjualan.unduh', ['prefix' => '5K']))->assertOk()->streamedContent();
        $baris = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $this->assertSame(['Tanggal', 'Jam', 'Router', 'Username', 'Profil', 'Comment', 'Harga', 'Harga jual', 'IP', 'MAC', 'Masa aktif'], $baris[0]);
        $this->assertCount(2, $baris);
        $this->assertSame([today()->format('Y-m-d'), '09:00:00', 'DIV-A', '5Kab2', '5-JAM', 'up-101-09.27.26-stok', '5000'], array_slice($baris[1], 0, 7));

        $semua = $this->get(route('penjualan.unduh'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=cmd", $semua);

        $this->get(route('penjualan.unduh', ['prefix' => '../x']))->assertSessionHasErrors('prefix');
    }
}
