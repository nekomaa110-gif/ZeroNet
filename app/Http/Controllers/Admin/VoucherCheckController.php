<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CekVoucherRequest;
use App\Models\DeletedVoucher;
use App\Models\Router;
use App\Models\VoucherBatch;
use App\Services\CekRentangService;
use App\Services\PencariKartuService;
use App\Services\VoucherLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VoucherCheckController extends Controller
{
    private const SYARAT = 'Isi Username, atau No + Batch, atau Password saja kalau username di kartu tidak terbaca.';

    public function index(Request $request): View
    {
        $hidup = VoucherBatch::orderByDesc('id')->pluck('code')->all();
        $ada   = array_flip($hidup);
        $lama  = DeletedVoucher::whereNotNull('batch_code')->distinct()->orderBy('batch_code')->pluck('batch_code')
            ->reject(fn ($c) => isset($ada[$c]))
            ->values()
            ->all();

        return view('voucher-check.index', [
            'kode'    => trim((string) $request->query('kode', '')),
            'slug'    => trim((string) $request->query('router', '')),
            'batch'   => trim((string) $request->query('batch', '')),
            'mode'    => $request->query('mode') === 'rentang' ? 'rentang' : 'satu',
            'routers' => Router::orderBy('id')->get(['slug', 'name']),
            'batches' => [...$hidup, ...$lama],
        ]);
    }

    public function cari(CekVoucherRequest $request, PencariKartuService $pencari, VoucherLookupService $lookup): JsonResponse
    {
        $d     = $request->validated();
        $kode  = trim((string) ($d['username'] ?? ''));
        $pass  = trim((string) ($d['password'] ?? ''));
        $no    = isset($d['no']) ? (int) $d['no'] : null;
        $batch = trim((string) ($d['batch'] ?? ''));
        $slug  = trim((string) ($d['router'] ?? ''));
        $kartu = CekVoucherRequest::kartu($d['kartu'] ?? null);

        $lewatPanel = $kode !== '' || $kartu !== null || ($no !== null && $batch !== '');

        if (! $lewatPanel && $pass === '') {
            return response()->json(['pesan' => self::SYARAT], 422);
        }

        $panel = null;

        if ($lewatPanel) {
            $panel = $pencari->cari(['no' => $no, 'username' => $kode, 'password' => $pass, 'kartu' => $kartu], $batch ?: null, $slug ?: null);

            if ($panel['status'] === 'galat') {
                return response()->json(['pesan' => $panel['pesan']], 422);
            }

            if ($panel['status'] === 'ambigu') {
                return response()->json([
                    'ambigu' => true,
                    'html'   => view('voucher-check._pilihan', ['pilihan' => $panel['pilihan'], 'ujung' => null])->render(),
                ]);
            }

            if ($panel['status'] === 'ketemu') {
                $kode = $panel['kartu']['username'];
                $pass = $pass !== '' ? $pass : $panel['kartu']['password'];
            } elseif ($kode === '') {
                return response()->json(['html' => view('voucher-check._kartu-panel', ['p' => $panel])->render()]);
            }
        }

        $hasil = $lookup->cek($kode, $pass, $slug !== '' ? [$slug] : []);

        return response()->json([
            'html' => view('voucher-check._hasil', ['r' => $hasil, 'panel' => $panel])->render(),
        ]);
    }

    public function rentang(CekVoucherRequest $request, CekRentangService $cek): JsonResponse
    {
        $d     = $request->validated();
        $ujung = fn (array $u) => [
            'no'       => isset($u['no']) ? (int) $u['no'] : null,
            'username' => trim((string) ($u['username'] ?? '')),
            'password' => trim((string) ($u['password'] ?? '')),
            'kartu'    => CekVoucherRequest::kartu($u['kartu'] ?? null),
        ];

        $h = $cek->rentang($ujung($d['dari']), $ujung($d['sampai']), trim((string) ($d['batch'] ?? '')) ?: null, trim((string) ($d['router'] ?? '')) ?: null);

        if ($h['status'] === 'galat') {
            return response()->json(['pesan' => $h['pesan']], 422);
        }

        if ($h['status'] === 'ambigu') {
            return response()->json([
                'ambigu' => true,
                'html'   => view('voucher-check._pilihan', ['pilihan' => $h['pilihan'], 'ujung' => $h['ujung']])->render(),
            ]);
        }

        return response()->json(['html' => view('voucher-check._rentang', ['h' => $h])->render()]);
    }
}
