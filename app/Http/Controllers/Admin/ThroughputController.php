<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LoadBalanceService;
use App\Services\MikrotikService;
use App\Services\TrafficService;
use Exception;
use Illuminate\Http\Request;

class ThroughputController extends Controller
{
    public const RANGES = [7, 14, 30];

    public function __construct(
        private TrafficService $traffic,
        private MikrotikService $mikrotik,
        private LoadBalanceService $lb,
    ) {}

    public function index()
    {
        $routers = collect($this->mikrotik->routers())->map(function ($cfg, $id) {
            $ifaces = $this->traffic->recordedInterfaces($id);
            if (! $ifaces && ($cfg['wan_interface'] ?? '') !== '') {
                $ifaces = [$cfg['wan_interface']];
            }

            return [
                'id'          => $id,
                'name'        => $cfg['name'],
                'host'        => $cfg['host'],
                'interfaces'  => $ifaces,
                'lb'          => (bool) $this->lb->known($id),
                'live_url'    => route('throughput.live', [$id, 'w' => 10]),
                'history_url' => route('throughput.history', $id),
                'router_url'  => route('routers.show', $id),
            ];
        })->values();

        return view('throughput.index', [
            'routers' => $routers,
            'ranges'  => self::RANGES,
        ]);
    }

    public function live(Request $request, string $router)
    {
        $this->mikrotik->routerConfig($router);
        $window = min(max((int) $request->query('w', 3), 2), 15);

        try {
            return response()->json($this->traffic->live($router, $window));
        } catch (Exception $e) {
            return response()->json(['online' => false, 'error' => $e->getMessage()]);
        }
    }

    public function history(Request $request, string $router)
    {
        $this->mikrotik->routerConfig($router);
        $days = (int) $request->query('days', 7);
        if (! in_array($days, self::RANGES, true)) {
            $days = 7;
        }

        return response()->json($this->traffic->history($router, $days));
    }
}
