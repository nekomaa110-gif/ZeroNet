<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\VoucherLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VoucherCheckController extends Controller
{
    public function index(Request $request): View
    {
        return view('voucher-check.index', [
            'kode'    => trim((string) $request->input('kode', '')),
            'pass'    => trim((string) $request->input('pass', '')),
            'slug'    => trim((string) $request->input('router', '')),
            'routers' => Router::orderBy('id')->get(['slug', 'name']),
        ]);
    }

    public function cari(Request $request, VoucherLookupService $service): JsonResponse
    {
        $data = $request->validate([
            'kode'   => ['nullable', 'string', 'max:64'],
            'pass'   => ['nullable', 'string', 'max:64'],
            'router' => ['nullable', 'string', 'max:64'],
        ]);

        $kode = trim((string) ($data['kode'] ?? ''));
        $pass = trim((string) ($data['pass'] ?? ''));

        if ($kode === '' && $pass === '') {
            return response()->json(['html' => '', 'pesan' => 'Isi kode voucher atau password kartunya dulu.'], 422);
        }

        $slug  = trim((string) ($data['router'] ?? ''));
        $hasil = $service->cek($kode, $pass, $slug !== '' ? [$slug] : []);

        return response()->json([
            'html' => view('voucher-check._hasil', ['r' => $hasil])->render(),
        ]);
    }
}
