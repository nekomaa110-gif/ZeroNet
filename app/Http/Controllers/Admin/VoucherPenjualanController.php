<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\VoucherSale;
use App\Services\VoucherSalesReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoucherPenjualanController extends Controller
{
    public const CETAK_MAKS_BARIS = 3000;

    public function index(Request $request, VoucherSalesReport $laporan)
    {
        $routers = $this->routers();
        [$filter, $router] = $this->filter($request, $routers);

        return view('penjualan.index', [
            'routers'   => $routers,
            'router'    => $router,
            'filter'    => $filter,
            'isian'     => $request->filled('dari') || $request->filled('sampai') ? $filter : ['dari' => '', 'sampai' => ''],
            'label'     => $this->labelRentang($filter['dari'], $filter['sampai']),
            'total'     => $laporan->total($filter),
            'bulanIni'  => $laporan->total(['dari' => now()->startOfMonth()->format('Y-m-d'), 'sampai' => now()->format('Y-m-d')] + $filter),
            'transaksi' => $laporan->transaksi($filter)->withQueryString(),
            'profiles'  => VoucherSale::query()->whereNotNull('profile')->distinct()->orderBy('profile')->pluck('profile'),
            'ragu'      => VoucherSale::where('waktu_ragu', true)->count(),
        ]);
    }

    public function unduh(Request $request, VoucherSalesReport $laporan): StreamedResponse
    {
        [$filter, $router] = $this->filter($request, $this->routers());

        $nama = 'penjualan-' . ($router?->slug ?? 'semua') . '-' . $filter['dari']
            . ($filter['sampai'] !== $filter['dari'] ? '-sd-' . $filter['sampai'] : '')
            . ($filter['prefix'] ? '-prefix-' . $filter['prefix'] : '') . '.csv';

        return response()->streamDownload(function () use ($laporan, $filter) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Tanggal', 'Jam', 'Router', 'Username', 'Profil', 'Comment', 'Harga', 'Harga jual', 'IP', 'MAC', 'Masa aktif']);

            foreach ($laporan->semuaTransaksi($filter) as $t) {
                $w = Carbon::parse($t->sold_at);
                fputcsv($out, array_map(fn ($v) => $this->selAman($v), [
                    $w->format('Y-m-d'), $w->format('H:i:s'), $t->router_name, $t->username, $t->profile,
                    $t->batch_comment, $t->price, $t->sprice, $t->ip, $t->mac, $t->validity,
                ]));
            }

            fclose($out);
        }, $nama, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function cetak(Request $request, VoucherSalesReport $laporan)
    {
        [$filter, $router] = $this->filter($request, $this->routers());
        $jumlah = $laporan->total($filter)['transaksi'];

        return view('penjualan.cetak', [
            'filter'    => $filter,
            'router'    => $router,
            'total'     => $laporan->total($filter),
            'perRouter' => $laporan->rekap($filter, ['router_name']),
            'perProfil' => $laporan->rekap($filter, ['router_name', 'profile']),
            'perHari'   => $filter['dari'] !== $filter['sampai'] ? $laporan->rekap($filter, ['tanggal']) : [],
            'transaksi' => $jumlah <= self::CETAK_MAKS_BARIS ? $laporan->semuaTransaksi($filter) : [],
            'jumlah'    => $jumlah,
            'maks'      => self::CETAK_MAKS_BARIS,
        ]);
    }

    private function routers(): Collection
    {
        return Router::orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name', 'last_sales_pull_at']);
    }

    private function filter(Request $request, Collection $routers): array
    {
        $data = $request->validate([
            'router'  => ['nullable', 'string', 'max:64'],
            'profile' => ['nullable', 'string', 'max:64'],
            'prefix'  => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]*$/'],
            'dari'    => ['nullable', 'date_format:Y-m-d'],
            'sampai'  => ['nullable', 'date_format:Y-m-d'],
        ]);

        $router = $routers->firstWhere('slug', $data['router'] ?? null);
        $sampai = $data['sampai'] ?? now()->format('Y-m-d');
        $dari   = $data['dari'] ?? $sampai;

        if ($dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        return [[
            'router_id' => $router?->id,
            'profile'   => $data['profile'] ?? null,
            'prefix'    => trim((string) ($data['prefix'] ?? '')) ?: null,
            'dari'      => $dari,
            'sampai'    => $sampai,
        ], $router];
    }

    private function labelRentang(string $dari, string $sampai): string
    {
        $hariIni = now()->format('Y-m-d');
        $kemarin = now()->subDay()->format('Y-m-d');
        $awal    = now()->startOfMonth()->format('Y-m-d');
        $pendek  = fn (string $d) => Carbon::parse($d)->locale('id')->translatedFormat(substr($d, 0, 4) === substr($hariIni, 0, 4) ? 'j M' : 'j M Y');

        return match (true) {
            $dari === $hariIni && $sampai === $hariIni => 'hari ini',
            $dari === $kemarin && $sampai === $kemarin => 'kemarin',
            $dari === $awal && $sampai === $hariIni    => 'bulan ini',
            $dari === $sampai                          => $pendek($dari),
            default                                    => $pendek($dari) . ' sampai ' . $pendek($sampai),
        };
    }

    private function selAman(mixed $nilai): string
    {
        $s = (string) ($nilai ?? '');

        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $s : $s;
    }
}
