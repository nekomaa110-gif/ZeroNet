<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\RadAcct;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\RadiusUserService;
use App\Services\RouterSnapshotService;
use App\Services\TrafficService;
use App\Services\VoucherSalesReport;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private MikrotikService $mikrotik,
        private RadiusUserService $radiusUsers,
        private TrafficService $traffic,
    ) {}

    public function index(): View
    {
        $stats   = $this->computeStats();
        $routers = collect($this->mikrotik->routers())
            ->map(fn ($cfg, $id) => [
                'id'   => $id,
                ...$cfg,
                'wans' => $this->traffic->recordedInterfaces($id, 1) ?: [$cfg['wan_interface'] ?? 'ether1'],
            ])
            ->values();

        $recentActivities = ActivityLog::with('user')->latest()->limit(5)->get();
        $penjualan        = auth()->user()?->isAdmin() ? app(VoucherSalesReport::class)->ringkasan() : null;
        $routerPenjualan  = $penjualan ? Router::orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name', 'last_sales_pull_at']) : collect();

        return view('dashboard', compact('stats', 'routers', 'recentActivities', 'penjualan', 'routerPenjualan'));
    }

    public function live(RouterSnapshotService $snap): JsonResponse
    {
        $poller  = $snap->pollerAlive();
        $routers = [];

        foreach (array_keys($this->mikrotik->routers()) as $id) {
            $routers[$id] = [
                'traffic' => $this->aman(fn () => $this->traffic->live($id)),
                'health'  => $this->aman(fn () => [
                    'online' => true,
                    'stats'  => $poller
                        ? $snap->stats($id)
                        : Cache::remember("dashboard.health.{$id}", 15, fn () => $this->mikrotik->stats($id)),
                ]),
            ];
        }

        return response()->json([
            'stats'   => $this->computeStats(),
            'routers' => $routers,
            'source'  => $poller ? 'poller' : 'direct',
            'time'    => now()->format('H:i:s'),
        ]);
    }

    private function aman(callable $ambil): array
    {
        try {
            return $ambil();
        } catch (Exception $e) {
            return ['online' => false, 'error' => $e->getMessage()];
        }
    }

    private function computeStats(): array
    {
        return Cache::remember(RadiusUserService::STATS_CACHE_KEY, 60, function () {
            $users = $this->radiusUsers->stats();

            return [
                'total_users'     => $users['total'],
                'active_users'    => $users['active'],
                'expired_users'   => $users['expired'],
                'disabled_users'  => $users['disabled'],
                'online_sessions' => RadAcct::whereNull('acctstoptime')->count(),
            ];
        });
    }
}
