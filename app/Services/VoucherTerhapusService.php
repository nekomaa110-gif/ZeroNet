<?php

namespace App\Services;

use App\Exceptions\KartuBerubah;
use App\Models\DeletedVoucher;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Support\Carbon;

class VoucherTerhapusService
{
    private const POTONG = 500;

    public function simpan(array $idKartu, string $cara, array $hasil, ?int $oleh): int
    {
        $id    = array_values(array_unique(array_map('intval', $idKartu)));
        $kartu = Voucher::with(['batch:id,code,router_name', 'router:id,name'])->whereIn('id', $id)->lockForUpdate()->get();

        if ($kartu->count() !== count($id)) {
            throw KartuBerubah::baru();
        }

        $waktu = now();
        foreach ($kartu->chunk(self::POTONG) as $potong) {
            DeletedVoucher::insert($potong->map(fn (Voucher $v) => $this->baris($v, $cara, $hasil, $oleh, $waktu))->values()->all());
        }

        return $kartu->count();
    }

    public function simpanBatch(VoucherBatch $batch, array $hasil, ?int $oleh): int
    {
        if (! VoucherBatch::whereKey($batch->id)->lockForUpdate()->first()) {
            throw KartuBerubah::baru();
        }

        Voucher::where('batch_id', $batch->id)->lockForUpdate()->pluck('id');

        $waktu  = now();
        $jumlah = 0;

        Voucher::with(['batch:id,code,router_name', 'router:id,name'])
            ->where('batch_id', $batch->id)
            ->chunkById(self::POTONG, function ($potong) use ($hasil, $oleh, $waktu, &$jumlah) {
                DeletedVoucher::insert($potong->map(fn (Voucher $v) => $this->baris($v, 'batch', $hasil, $oleh, $waktu))->values()->all());
                $jumlah += $potong->count();
            });

        return $jumlah;
    }

    private function baris(Voucher $v, string $cara, array $hasil, ?int $oleh, Carbon $waktu): array
    {
        $keadaan = $hasil[$v->id]['keadaan'] ?? null;

        return [
            'voucher_id'   => $v->id,
            'batch_id'     => $v->batch_id,
            'batch_code'   => $v->batch?->code,
            'router_id'    => $v->router_id,
            'router_name'  => $v->router?->name ?? $v->batch?->router_name,
            'nomor'        => $v->nomor,
            'username'     => $v->username,
            'password'     => $v->password,
            'profile'      => $v->profile,
            'comment'      => $v->comment,
            'price'        => $v->price,
            'status'       => $keadaan['status'] ?? $v->status_efektif,
            'sync_status'  => $v->sync_status,
            'activated_at' => $keadaan ? $keadaan['activated_at'] : $v->activated_at,
            'expired_at'   => $keadaan ? $keadaan['expired_at'] : $v->expired_at,
            'printed_at'   => $v->printed_at,
            'uptime_used'  => (int) ($keadaan ? $keadaan['uptime_used'] : $v->uptime_used),
            'hasil_router' => $hasil[$v->id]['hasil'] ?? 'tidak_dicabut',
            'cara'         => $cara,
            'sumber'       => 'panel',
            'deleted_by'   => $oleh,
            'deleted_at'   => $waktu,
            'created_at'   => $waktu,
            'updated_at'   => $waktu,
        ];
    }
}
