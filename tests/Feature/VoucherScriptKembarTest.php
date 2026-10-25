<?php

namespace Tests\Feature;

use App\Services\VoucherScriptBuilder;
use Tests\TestCase;

class VoucherScriptKembarTest extends TestCase
{
    public function test_login_script_iso_dan_lama_punya_penjaga_kembar(): void
    {
        $b = new VoucherScriptBuilder();

        foreach (['6.49.19', '7.20.8'] as $versi) {
            $s = $b->loginScript($versi);

            $this->assertStringContainsString(':if ([:len $uid] = 1) do={', $s, $versi);
            $this->assertStringContainsString(':if ([:len $uid] > 1) do={', $s, $versi);
            $this->assertStringContainsString('zeronet: nama kembar $user, masa aktif tidak dicap', $s, $versi);
            $this->assertStringContainsString(':global znKembar', $s, $versi);
            $this->assertStringContainsString(':if ([:typeof ($znKembar->$user)] = "nothing") do={', $s, $versi);
            $this->assertStringNotContainsString(':if ([:len $uid] > 0)', $s, $versi);
        }
    }

    public function test_expire_script_cek_nama_unik_tanpa_membaca_password(): void
    {
        $s = (new VoucherScriptBuilder())->expireScript(['zn-uji' => 'ntf']);

        $this->assertStringContainsString('find where name=$nm]] = 1', $s);
        $this->assertStringContainsString('zeronet-expire: nama kembar $nm dilewati', $s);
        $this->assertStringContainsString(':global znKembarExp', $s);
        $this->assertStringNotContainsString('password', $s);
    }

    public function test_expire_script_putus_sesi_hanya_di_cabang_unik(): void
    {
        foreach (['ntf', 'rem'] as $mode) {
            $s = (new VoucherScriptBuilder())->expireScript(['zn-uji' => $mode]);

            $awal = strpos($s, 'find where name=$nm]] = 1) do={');
            $putus = strpos($s, 'active remove');
            $lain = strpos($s, "} else={\n", $awal);

            $this->assertNotFalse($awal, $mode);
            $this->assertNotFalse($putus, $mode);
            $this->assertNotFalse($lain, $mode);
            $this->assertSame(1, substr_count($s, 'active remove'), $mode);
            $this->assertGreaterThan($awal, $putus, $mode);
            $this->assertLessThan($lain, $putus, $mode);
        }
    }

    public function test_on_login_off_kunci_mac_hanya_nama_unik(): void
    {
        $s = (new VoucherScriptBuilder())->profileOnLogin(
            'Bebas',
            ['expmode' => 'off', 'price' => 3000, 'validity' => '', 'sprice' => 0, 'lock' => true, 'record' => false],
            'zeronet-onlogin',
        );

        $this->assertSame(
            ':put (",,3000,,,noexp,Enable,")'
            . '; [:local mac $"mac-address"; :local u [/ip hotspot user find where name=$user]; :if ([:len $u] = 1) do={ /ip hotspot user set $u mac-address=$mac }]',
            $s,
        );
    }
}
