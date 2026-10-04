<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\HotspotMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class HotspotMonitorController extends Controller
{
    public function __construct(private HotspotMonitorService $monitor) {}

    public function index(Request $request)
    {
        $routers = Router::orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name']);
        $pilih   = $routers->firstWhere('slug', $request->query('router')) ?? $routers->first();

        return view('hotspot.index', ['routers' => $routers, 'router' => $pilih]);
    }

    public function data(Router $router): JsonResponse
    {
        return response()->json(['success' => true] + $this->monitor->data($router));
    }

    public function putuskan(Request $request, Router $router, string $id): JsonResponse
    {
        return $this->hapus($request, $router, $id, 'active');
    }

    public function hapusCookie(Request $request, Router $router, string $id): JsonResponse
    {
        return $this->hapus($request, $router, $id, 'cookie');
    }

    private function hapus(Request $request, Router $router, string $id, string $bagian): JsonResponse
    {
        $data = $request->validate([
            'user' => ['required', 'string', 'max:64'],
            'mac'  => ['required', 'string', 'max:17'],
        ]);

        try {
            $baris = $bagian === 'active'
                ? $this->monitor->putuskan($router, $id, $data['user'], $data['mac'])
                : $this->monitor->hapusCookie($router, $id, $data['user'], $data['mac']);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }

        ActivityLogService::log(
            action: $bagian === 'active' ? 'hotspot_kick' : 'hotspot_cookie_hapus',
            description: ($bagian === 'active' ? 'Memutus sesi hotspot ' : 'Menghapus mac-cookie ')
                . "{$data['user']} ({$data['mac']}) di router {$router->name}",
            subjectType: 'router',
            subjectId: $router->slug,
            properties: ['id' => $id, 'baris' => $baris],
        );

        return response()->json(['success' => true]);
    }
}
