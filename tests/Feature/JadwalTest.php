<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class JadwalTest extends TestCase
{
    public function test_opsi_tanpa_nilai_di_jadwal_tidak_diberi_nilai(): void
    {
        Artisan::call('schedule:list');

        $perintah = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->filter();

        $penuh = $perintah->first(fn ($c) => str_contains($c, 'voucher:penjualan') && str_contains($c, '--penuh'));
        $this->assertNotNull($penuh, 'jadwal penarikan penuh harian tidak ditemukan');
        $this->assertDoesNotMatchRegularExpression('/--penuh=/', $penuh);
    }
}
