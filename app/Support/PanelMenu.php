<?php

namespace App\Support;

use App\Models\Invoice;
use Illuminate\Support\Collection;

class PanelMenu
{
    private static ?Collection $cache = null;

    private static ?int $cacheFor = null;

    public static function items(): Collection
    {
        $rid = spl_object_id(request());
        if (self::$cache !== null && self::$cacheFor === $rid) {
            return self::$cache;
        }
        self::$cacheFor = $rid;

        $user = auth()->user();
        $isAdmin = $user && method_exists($user, 'isAdmin') ? $user->isAdmin() : ($user?->role === 'admin');

        $billingPending = $isAdmin
            ? Invoice::whereIn('status', ['draft', 'pending_confirmation'])->count()
            : 0;

        $menu = [
            [
                'label' => 'Dashboard', 'route' => 'dashboard', 'match' => ['dashboard'],
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>',
            ],
            [
                'label' => 'Pelanggan',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>',
                'children' => [
                    ['label' => 'User Hotspot', 'route' => 'user-hotspot.index', 'match' => ['user-hotspot.*']],
                    ['label' => 'Paket', 'route' => 'packages.index', 'match' => ['packages.*']],
                    ['label' => 'Billing', 'route' => 'billing.index', 'match' => ['billing.*'], 'admin' => true, 'count' => $billingPending],
                ],
            ],
            [
                'label' => 'Voucher',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M13 5v14" stroke-dasharray="2 3"/></svg>',
                'children' => [
                    ['label' => 'Generator Voucher', 'route' => 'vouchers.index', 'match' => ['vouchers.*', 'voucher-scripts.*']],
                    ['label' => 'Cek Voucher', 'route' => 'voucher-check.index', 'match' => ['voucher-check.*']],
                    ['label' => 'Penjualan', 'route' => 'penjualan.index', 'match' => ['penjualan.*'], 'admin' => true],
                    ['label' => 'Template Cetak', 'route' => 'voucher-templates.index', 'match' => ['voucher-templates.*'], 'admin' => true],
                ],
            ],
            [
                'label' => 'Jaringan',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="14" width="20" height="7" rx="1"/><path d="M6 17.5h.01M10 17.5h.01"/><path d="M15 10v4"/><path d="M18.5 6.5a5 5 0 0 0-7 0"/></svg>',
                'children' => [
                    ['label' => 'Manajemen Router', 'route' => 'routers.index', 'match' => ['routers.*']],
                    ['label' => 'Hotspot Aktif', 'route' => 'hotspot.index', 'match' => ['hotspot.*']],
                    ['label' => 'Load Balance', 'route' => 'load-balance.index', 'match' => ['load-balance.*']],
                    ['label' => 'Throughput', 'route' => 'throughput.index', 'match' => ['throughput.*']],
                    ['label' => 'VPN L2TP', 'route' => 'l2tp.index', 'match' => ['l2tp.*'], 'admin' => true],
                ],
            ],
            [
                'label' => 'WhatsApp', 'admin' => true,
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
                'children' => [
                    ['label' => 'WhatsApp Gateway', 'route' => 'whatsapp.index', 'match' => ['whatsapp.*']],
                    ['label' => 'Template Pesan', 'route' => 'message-templates.index', 'match' => ['message-templates.*']],
                    ['label' => 'Notifikasi WA', 'route' => 'admin-notifications.index', 'match' => ['admin-notifications.*']],
                ],
            ],
            [
                'label' => 'Website', 'admin' => true,
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
                'children' => [
                    ['label' => 'Area Layanan', 'route' => 'coverage-areas.index', 'match' => ['coverage-areas.*']],
                ],
            ],
            [
                'label' => 'Log',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/></svg>',
                'children' => [
                    ['label' => 'Log Hotspot', 'route' => 'hotspot-logs.index', 'match' => ['hotspot-logs.*']],
                    ['label' => 'Log Aktivitas', 'route' => 'activity-logs.index', 'match' => ['activity-logs.*'], 'admin' => true],
                ],
            ],
        ];

        $allowed = fn (array $m): bool => $isAdmin || empty($m['admin']);

        return self::$cache = collect($menu)
            ->filter($allowed)
            ->map(function (array $m) use ($allowed) {
                if (! isset($m['children'])) {
                    return $m + ['active' => self::isActive($m['match'])];
                }
                $kids = array_values(array_filter($m['children'], $allowed));
                if (count($kids) === 1) {
                    return $kids[0] + ['icon' => $m['icon'], 'active' => self::isActive($kids[0]['match'])];
                }
                $m['children'] = array_map(fn ($c) => $c + ['active' => self::isActive($c['match'])], $kids);
                $m['active'] = collect($m['children'])->contains('active', true);
                $m['count'] = collect($kids)->sum(fn ($c) => $c['count'] ?? 0);

                return $m;
            })
            ->filter(fn (array $m) => ! isset($m['children']) || count($m['children']) > 0)
            ->values();
    }

    public static function crumb(): array
    {
        foreach (self::items() as $item) {
            if (! isset($item['children'])) {
                if ($item['active']) {
                    return [['label' => $item['label'], 'url' => route($item['route'])]];
                }
                continue;
            }
            foreach ($item['children'] as $child) {
                if ($child['active']) {
                    return [
                        ['label' => $item['label'], 'url' => null],
                        ['label' => $child['label'], 'url' => route($child['route'])],
                    ];
                }
            }
        }

        if (request()->routeIs('profile.*', 'two-factor.*', 'operators.*', 'password.*')) {
            return [['label' => 'Akun', 'url' => null]];
        }

        return [];
    }

    private static function isActive(array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (request()->routeIs($p)) {
                return true;
            }
        }

        return false;
    }
}
