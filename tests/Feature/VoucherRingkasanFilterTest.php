<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VoucherRingkasanFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['div-a' => ['ready', 'active'], 'div-b' => ['ready', 'ready', 'ready']] as $slug => $status) {
            $router = Router::create(['slug' => $slug, 'name' => strtoupper($slug), 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x']);
            $batch  = VoucherBatch::create([
                'router_id' => $router->id, 'router_name' => $router->name, 'code' => "up-001-{$slug}",
                'profile' => '5-JAM', 'quantity' => count($status), 'status' => 'success',
            ]);
            foreach ($status as $i => $s) {
                Voucher::create([
                    'batch_id' => $batch->id, 'router_id' => $router->id, 'username' => "{$slug}-{$i}", 'password' => 'p',
                    'profile' => '5-JAM', 'status' => $s, 'sync_status' => 'success',
                ]);
            }
        }

        $this->actingAs(User::create([
            'name' => 'admin', 'username' => 'adminuji', 'email' => 'admin@example.test', 'password' => Hash::make('x'),
            'role' => 'admin', 'is_active' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]));
    }

    private static function ringkasan(string $html): array
    {
        preg_match_all('#<div class="rd-label">([^<]+)</div>\s*<div class="rd-value[^"]*">([^<]+)</div>#', $html, $m);

        return array_combine(array_map('trim', $m[1]), array_map('trim', $m[2]));
    }

    private static function oob(string $html): ?string
    {
        return preg_match('#<template data-live-oob="vc-ringkasan">(.*?)</template>#s', $html, $m) ? $m[1] : null;
    }

    public function test_halaman_penuh_ringkasan_tersaring_router_dan_punya_id_tetap(): void
    {
        $isi = $this->get(route('vouchers.index', ['router' => 'div-a']))->assertOk()->getContent();

        $this->assertStringContainsString('id="vc-ringkasan"', $isi);
        $this->assertSame(['Total voucher' => '2', 'Belum dipakai' => '1', 'Terjual' => '1', 'Belum masuk router' => '0'], self::ringkasan($isi));
        $this->assertNull(self::oob($isi));
    }

    public function test_respons_live_filter_membawa_ringkasan_tersaring_untuk_diganti_di_halaman(): void
    {
        $isi = $this->get(route('vouchers.index', ['router' => 'div-b']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('up-001-div-b')->assertDontSee('up-001-div-a')->getContent();

        $oob = self::oob($isi);
        $this->assertNotNull($oob, 'respons live tidak membawa ringkasan');
        $this->assertStringContainsString('id="vc-ringkasan"', $oob);
        $this->assertSame(['Total voucher' => '3', 'Belum dipakai' => '3', 'Terjual' => '0', 'Belum masuk router' => '0'], self::ringkasan($oob));

        $semua = self::oob($this->get(route('vouchers.index'), ['X-Requested-With' => 'XMLHttpRequest'])->getContent());
        $this->assertSame(['Total voucher' => '5', 'Belum dipakai' => '4', 'Terjual' => '1', 'Belum masuk router' => '0'], self::ringkasan($semua));
    }
}
