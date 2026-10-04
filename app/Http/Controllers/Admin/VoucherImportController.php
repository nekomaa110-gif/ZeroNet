<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportVoucherMikhmon;
use App\Models\Router;
use App\Services\VoucherImportService;
use Illuminate\Http\JsonResponse;
use Throwable;

class VoucherImportController extends Controller
{
    public function pratinjau(Router $router, VoucherImportService $import): JsonResponse
    {
        try {
            return response()->json(['success' => true] + VoucherImportService::pratinjau($import->rencana($router)));
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Router tidak bisa dibaca: ' . $e->getMessage()], 422);
        }
    }

    public function impor(Router $router): JsonResponse
    {
        ImportVoucherMikhmon::dispatch($router->id, auth()->id());

        return response()->json([
            'success' => true,
            'message' => "Impor voucher {$router->name} berjalan di antrean. Hasilnya tercatat di Log Aktivitas; mengulang impor tidak menggandakan voucher.",
        ], 202);
    }
}
