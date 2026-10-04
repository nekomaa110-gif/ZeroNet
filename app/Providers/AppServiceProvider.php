<?php

namespace App\Providers;

use App\Auth\RadcheckUserProvider;
use App\Services\ActivityLogService;
use App\Services\MikrotikService;
use App\Services\RouterGateway;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RouterGateway::class);
        $this->app->singleton(MikrotikService::class);
    }

    public function boot(): void
    {
        Auth::provider('radcheck', fn () => new RadcheckUserProvider());

        Event::listen(Login::class, function (Login $event) {
            if ($event->guard === 'web') {
                ActivityLogService::log(
                    action: 'login',
                    description: "Login berhasil: {$event->user->username}",
                    subjectType: 'user',
                    subjectId: (string) $event->user->id,
                    userId: $event->user->id,
                );
                return;
            }
            if ($event->guard === 'customer') {
                $username = $event->user->getAuthIdentifier();
                $name = $event->user->name ?? null;
                $label = $name ? "{$username} ({$name})" : $username;
                ActivityLogService::log(
                    action: 'customer_login',
                    description: "Pelanggan akses portal: {$label}",
                    subjectType: 'customer',
                    subjectId: $username,
                    properties: [
                        'ip' => request()?->ip(),
                        'ua' => substr((string) request()?->userAgent(), 0, 200),
                    ],
                );
            }
        });

        Event::listen(Logout::class, function (Logout $event) {
            if (! $event->user) return;

            if ($event->guard === 'web') {
                ActivityLogService::log(
                    action: 'logout',
                    description: "Logout: {$event->user->username}",
                    subjectType: 'user',
                    subjectId: (string) $event->user->id,
                    userId: $event->user->id,
                );
                return;
            }

            if ($event->guard === 'customer') {
                $username = $event->user->getAuthIdentifier();
                ActivityLogService::log(
                    action: 'customer_logout',
                    description: "Pelanggan keluar portal: {$username}",
                    subjectType: 'customer',
                    subjectId: $username,
                    properties: ['ip' => request()?->ip()],
                );
            }
        });

        Event::listen(Failed::class, function (Failed $event) {
            if ($event->guard === 'web') {
                $attempted = $event->credentials['username'] ?? $event->credentials['email'] ?? '(unknown)';
                ActivityLogService::log(
                    action: 'login_failed',
                    description: "Login gagal untuk: {$attempted}",
                    subjectType: 'user',
                    subjectId: $event->user?->id ? (string) $event->user->id : null,
                    userId: $event->user?->id,
                );
                return;
            }
            if ($event->guard === 'customer') {
                $attempted = $event->credentials['username'] ?? '(unknown)';
                ActivityLogService::log(
                    action: 'customer_login_failed',
                    description: "Pelanggan gagal akses portal: {$attempted}",
                    subjectType: 'customer',
                    subjectId: $attempted,
                    properties: [
                        'ip' => request()?->ip(),
                        'ua' => substr((string) request()?->userAgent(), 0, 200),
                    ],
                );
            }
        });
    }
}
