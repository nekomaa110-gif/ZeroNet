<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VoucherSalesReport
{
    public const CACHE_DETIK = 60;

    private const KOLOM_ANGKA = 'COUNT(*) AS transaksi,
        COALESCE(SUM(price), 0) AS omzet,
        SUM(sprice IS NOT NULL AND price IS NOT NULL) AS transaksi_harga_jual,
        COALESCE(SUM(CASE WHEN sprice IS NOT NULL AND price IS NOT NULL THEN sprice END), 0) AS harga_jual,
        COALESCE(SUM(CASE WHEN sprice IS NOT NULL AND price IS NOT NULL THEN CAST(sprice AS SIGNED) - CAST(price AS SIGNED) END), 0) AS margin';

    public function ringkasan(): array
    {
        return Cache::remember('voucher.penjualan.ringkasan', self::CACHE_DETIK, function () {
            $sekarang = now();

            return [
                'hari_ini'   => $this->perRouter($sekarang->copy()->startOfDay(), $sekarang->copy()->endOfDay()),
                'bulan_ini'  => $this->perRouter($sekarang->copy()->startOfMonth(), $sekarang->copy()->endOfMonth()),
                'voucher'    => $this->voucherPerRouter(),
                'waktu_ragu' => DB::table('voucher_sales')->where('waktu_ragu', true)->count(),
                'diperbarui' => $sekarang->toIso8601String(),
            ];
        });
    }

    public function total(array $filter): array
    {
        return $this->angka($this->saring($filter)->selectRaw(self::KOLOM_ANGKA)->first());
    }

    public function rekap(array $filter, array $kelompok): array
    {
        $kolom = array_map(fn ($k) => $k === 'tanggal' ? 'DATE(sold_at) AS tanggal' : $k, $kelompok);

        return $this->saring($filter)
            ->selectRaw(implode(', ', $kolom) . ', ' . self::KOLOM_ANGKA)
            ->groupBy($kelompok)
            ->orderBy($kelompok[0])
            ->get()
            ->map(fn ($r) => array_intersect_key((array) $r, array_flip($kelompok)) + $this->angka($r))
            ->all();
    }

    public function transaksi(array $filter, int $perHalaman = 50)
    {
        return $this->saring($filter)
            ->select(['id', 'router_id', 'router_name', 'sold_at', 'waktu_ragu', 'username', 'profile', 'price', 'sprice', 'validity', 'ip', 'mac', 'batch_comment'])
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->paginate($perHalaman);
    }

    public function semuaTransaksi(array $filter): iterable
    {
        return $this->saring($filter)
            ->select(['router_name', 'sold_at', 'username', 'profile', 'price', 'sprice', 'validity', 'ip', 'mac', 'batch_comment'])
            ->orderBy('sold_at')
            ->orderBy('id')
            ->cursor();
    }

    private function saring(array $filter): Builder
    {
        return DB::table('voucher_sales')
            ->where('waktu_ragu', false)
            ->when($filter['dari'] ?? null, fn ($q, $d) => $q->where('sold_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filter['sampai'] ?? null, fn ($q, $d) => $q->where('sold_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($filter['router_id'] ?? null, fn ($q, $id) => $q->where('router_id', $id))
            ->when($filter['profile'] ?? null, fn ($q, $p) => $q->where('profile', $p))
            ->when($filter['prefix'] ?? null, fn ($q, $p) => $q->where('username', 'like', addcslashes($p, '%_\\') . '%'));
    }

    private function perRouter(Carbon $dari, Carbon $sampai): array
    {
        $baris = $this->saring(['dari' => $dari, 'sampai' => $sampai])
            ->selectRaw('router_id, ' . self::KOLOM_ANGKA)
            ->groupBy('router_id')
            ->get();

        $per = $baris->mapWithKeys(fn ($r) => [(int) $r->router_id => $this->angka($r)])->all();

        return ['total' => $this->jumlahkan($per), 'per_router' => $per];
    }

    private function jumlahkan(array $baris): array
    {
        $total = array_fill_keys(['transaksi', 'omzet', 'transaksi_harga_jual', 'harga_jual', 'margin'], 0);

        foreach ($baris as $angka) {
            foreach ($total as $k => $_) {
                $total[$k] += $angka[$k];
            }
        }

        return $total;
    }

    private function voucherPerRouter(): array
    {
        $hasil = [];

        Voucher::query()
            ->selectRaw('router_id, ' . Voucher::statusEfektifSql() . ' AS s, COUNT(*) AS n', Voucher::ikatanStatusEfektif())
            ->whereNotNull('router_id')
            ->where('sync_status', 'success')
            ->groupBy('router_id', 's')
            ->get()
            ->each(function ($r) use (&$hasil) {
                $hasil[(int) $r->router_id][$r->s] = (int) $r->n;
            });

        return $hasil;
    }

    private function angka(object $r): array
    {
        return [
            'transaksi'            => (int) $r->transaksi,
            'omzet'                => (int) $r->omzet,
            'transaksi_harga_jual' => (int) $r->transaksi_harga_jual,
            'harga_jual'           => (int) $r->harga_jual,
            'margin'               => (int) $r->margin,
        ];
    }
}
