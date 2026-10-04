<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RadCheck;
use App\Models\RadPostAuth;
use App\Models\Router;
use App\Models\RouterLog;
use App\Services\RouterSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class HotspotLogController extends Controller
{
    public function index(Request $request): View
    {
        if ($request->query('sumber') === 'router') {
            return $this->router($request);
        }

        $search   = $request->input('search', '');
        $status   = $request->input('status', '');
        $dateFrom = $request->input('date_from', '');
        $dateTo   = $request->input('date_to', '');
        $showRaw  = $request->boolean('show_raw');

        $query = RadPostAuth::query()
            ->search($search)
            ->byStatus($status)
            ->byDateFrom($dateFrom)
            ->byDateTo($dateTo);

        if (! $showRaw) {
            $query->cleaned();
        }

        $logs = $query
            ->orderByDesc('authdate')
            ->simplePaginate(30)
            ->withQueryString();

        $rejectReasons = $this->resolveRejectReasons($logs);
        $userIps       = $this->resolveUserIps($logs);

        return view('hotspot-logs.index', compact('logs', 'search', 'status', 'dateFrom', 'dateTo', 'rejectReasons', 'userIps', 'showRaw'));
    }

    public function tonton(RouterSnapshotService $snap): JsonResponse
    {
        foreach (Router::pluck('slug') as $slug) {
            $snap->watchLog($slug);
        }

        return response()->json(['terbaru' => (int) RouterLog::max('id')]);
    }

    private function router(Request $request): View
    {
        $data = $request->validate([
            'router'   => ['nullable', 'string', 'max:64'],
            'cari'     => ['nullable', 'string', 'max:64'],
            'kejadian' => ['nullable', 'in:masuk,keluar,gagal,mencoba,lain'],
            'dari'     => ['nullable', 'date_format:Y-m-d'],
            'sampai'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $routers = Router::orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name']);
        $router  = $routers->firstWhere('slug', $data['router'] ?? null);
        $cari    = trim((string) ($data['cari'] ?? ''));

        $logs = RouterLog::query()
            ->with('router:id,name')
            ->when($router, fn ($q) => $q->where('router_id', $router->id))
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w->where('pengguna', 'like', '%' . addcslashes($cari, '%_\\') . '%')->orWhere('ip', $cari)))
            ->when($data['kejadian'] ?? null, fn ($q, $k) => $q->where('kejadian', $k))
            ->when($data['dari'] ?? null, fn ($q, $d) => $q->where('waktu', '>=', $d . ' 00:00:00'))
            ->when($data['sampai'] ?? null, fn ($q, $d) => $q->where('waktu', '<=', $d . ' 23:59:59'))
            ->orderByDesc('waktu')
            ->orderByDesc('id')
            ->simplePaginate(50)
            ->withQueryString();

        return view('hotspot-logs.router', [
            'logs'    => $logs,
            'routers' => $routers,
            'router'  => $router,
            'filter'  => $data + ['cari' => $cari],
            'terbaru' => (int) RouterLog::max('id'),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $afterId = (int) $request->input('after', 0);
        $showRaw = $request->boolean('show_raw');

        if (!$afterId) {
            return response()->json(['count' => 0]);
        }

        $query = RadPostAuth::where('id', '>', $afterId);

        if (! $showRaw) {
            $query->cleaned();
        }

        $newLogs = $query->orderByDesc('id')->limit(20)->get();

        if ($newLogs->isEmpty()) {
            return response()->json(['count' => 0]);
        }

        $rejectReasons = $this->resolveRejectReasons($newLogs);
        $userIps       = $this->resolveUserIps($newLogs);

        $html = $newLogs->map(fn($log) =>
            view('hotspot-logs._row', [
                'log'           => $log,
                'rejectReasons' => $rejectReasons,
                'userIps'       => $userIps,
                'isNew'         => true,
            ])->render()
        )->implode('');

        return response()->json([
            'count'  => $newLogs->count(),
            'max_id' => $newLogs->max('id'),
            'html'   => $html,
        ]);
    }

    private function resolveUserIps($logs): array
    {
        $usernames = $logs->pluck('username')->unique()->values();

        if ($usernames->isEmpty()) {
            return [];
        }

        $sub = DB::table('radacct')
            ->whereIn('username', $usernames)
            ->select('username', DB::raw('MAX(acctstarttime) as max_start'))
            ->groupBy('username');

        $rows = DB::table('radacct as a')
            ->joinSub($sub, 'b', fn($j) => $j->on('a.username', '=', 'b.username')
                ->on('a.acctstarttime', '=', 'b.max_start'))
            ->select('a.username', 'a.framedipaddress')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $ip = $row->framedipaddress ?? '';
            if ($ip && $ip !== '0.0.0.0') {
                $result[$row->username] = $ip;
            }
        }

        return $result;
    }

    private function resolveRejectReasons($logs): array
    {
        $failed = $logs->filter(fn($l) => !$l->isSuccess())
            ->pluck('username')->unique()->values();

        if ($failed->isEmpty()) {
            return [];
        }

        $blocked = RadCheck::whereIn('username', $failed)
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->pluck('username')
            ->flip()->map(fn() => true);

        $expiredExpiration = RadCheck::whereIn('username', $failed)
            ->where('attribute', 'Expiration')
            ->whereRaw("STR_TO_DATE(value, '%d %b %Y %H:%i:%s') < NOW()")
            ->pluck('username')
            ->flip()->map(fn() => true);

        $activeSessions = DB::table('radacct')
            ->whereIn('username', $failed)
            ->whereNull('acctstoptime')
            ->pluck('username')
            ->flip()->map(fn() => true);

        $reasons = [];
        foreach ($failed as $username) {
            if ($blocked->has($username)) {
                $reasons[$username] = 'Akun dinonaktifkan';
            } elseif ($expiredExpiration->has($username)) {
                $reasons[$username] = 'Voucher sudah expired';
            } elseif ($activeSessions->has($username)) {
                $reasons[$username] = 'Akun sedang digunakan';
            } else {
                $reasons[$username] = 'Username atau password salah';
            }
        }

        return $reasons;
    }
}
