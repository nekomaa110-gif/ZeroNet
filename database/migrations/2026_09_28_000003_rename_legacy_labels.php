<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAMA_TEMPLATE = [
        'mikhmon-standar' => ['Mikhmon standar (kuning)', 'Standar (kuning)'],
        'mikhmon-kecil'   => ['Mikhmon kecil (hijau)', 'Kecil (hijau)'],
        'mikhmon-thermal' => ['Mikhmon thermal', 'Thermal'],
    ];

    private const BATCH_LAMA = 'mikhmon-tanpa-batch';

    private const BATCH_BARU = 'tanpa-batch';

    public function up(): void
    {
        foreach (self::NAMA_TEMPLATE as $kunci => [$lama, $baru]) {
            DB::table('print_templates')->where('bawaan', $kunci)->where('name', $lama)->update(['name' => $baru]);
        }

        $this->ganti(self::BATCH_LAMA, self::BATCH_BARU);
    }

    public function down(): void
    {
        foreach (self::NAMA_TEMPLATE as $kunci => [$lama, $baru]) {
            DB::table('print_templates')->where('bawaan', $kunci)->where('name', $baru)->update(['name' => $lama]);
        }

        $this->ganti(self::BATCH_BARU, self::BATCH_LAMA);
    }

    private function ganti(string $dari, string $ke): void
    {
        DB::transaction(function () use ($dari, $ke) {
            DB::table('voucher_batches')->where('code', 'like', $dari . '@%')->update([
                'code' => DB::raw('CONCAT(' . DB::getPdo()->quote($ke) . ', SUBSTRING(code, ' . (strlen($dari) + 1) . '))'),
            ]);
            DB::table('voucher_batches')->where('label', $dari)->update(['label' => $ke]);
            DB::table('vouchers')->where('comment', $dari)->update(['comment' => $ke]);
        });
    }
};
