<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $search   = $request->input('search', '');
        $dateFrom = $request->input('date_from', '');
        $dateTo   = $request->input('date_to', '');

        $logs = ActivityLog::with('user')
            ->when($search, function ($q) use ($search) {
                $q->leftJoin('users', 'users.id', '=', 'activity_logs.user_id')
                  ->select('activity_logs.*')
                  ->where(function ($q2) use ($search) {
                      $q2->where('activity_logs.action', 'like', "%{$search}%")
                         ->orWhere('activity_logs.description', 'like', "%{$search}%")
                         ->orWhere('users.name', 'like', "%{$search}%")
                         ->orWhere('users.username', 'like', "%{$search}%");
                  });
            })
            ->when($dateFrom, fn ($q) => $q->whereDate('activity_logs.created_at', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->whereDate('activity_logs.created_at', '<=', $dateTo))
            ->orderByDesc('activity_logs.created_at')
            ->paginate(25)
            ->withQueryString();

        if ($request->ajax()) {
            return view('activity-logs._results', compact('logs', 'search', 'dateFrom', 'dateTo'));
        }

        return view('activity-logs.index', compact('logs', 'search', 'dateFrom', 'dateTo'));
    }
}
