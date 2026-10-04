<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\RouterPersiapanService;
use Illuminate\Http\JsonResponse;
use Throwable;

class RouterPersiapanController extends Controller
{
    public function __construct(private RouterPersiapanService $svc) {}

    public function show(Router $router)
    {
        return view('routers.siapkan', [
            'router'     => $router,
            'namaAkun'   => RouterPersiapanService::namaAkun(),
            'policyAkun' => (array) config('services.mikrotik.akun_panel.policy', []),
        ]);
    }

    public function periksa(Router $router): JsonResponse
    {
        try {
            return response()->json(['success' => true] + $this->svc->periksa($router));
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function akun(Router $router): JsonResponse
    {
        $sebelumnya = $router->username;

        try {
            $hasil = $this->svc->pasangAkun($router);
        } catch (Throwable $e) {
            ActivityLogService::log(
                action: 'router_api_account_failed',
                description: 'Gagal menyiapkan akun ' . RouterPersiapanService::namaAkun() . " di router {$router->name}",
                subjectType: 'router',
                subjectId: $router->slug,
                properties: ['akun_sebelumnya' => $sebelumnya, 'error' => $e->getMessage()],
            );

            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        ActivityLogService::log(
            action: 'router_api_account',
            description: "Akun {$router->username} di router {$router->name} {$hasil['akun']}, panel kini memakai akun ini",
            subjectType: 'router',
            subjectId: $router->slug,
            properties: ['akun_sebelumnya' => $sebelumnya] + $hasil,
        );

        $pesan = match ($hasil['akun']) {
            'password diganti' => "Password akun {$router->username} diganti dan sudah dipakai panel.",
            default            => "Panel kini masuk ke {$hasil['identitas']} memakai akun {$router->username}. Akun {$sebelumnya} tidak diubah.",
        };

        return response()->json(['success' => true, 'message' => $pesan, 'hasil' => $hasil]);
    }
}
