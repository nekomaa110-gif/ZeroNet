<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            $mainDomain    = (string) config('app.domain');
            $billingDomain = (string) config('app.billing_domain');

            \Illuminate\Support\Facades\Route::middleware([
                    \Illuminate\Routing\Middleware\SubstituteBindings::class,
                    'throttle:20,1',
                ])
                ->domain($mainDomain)
                ->get('/i/{slug}', [\App\Http\Controllers\Customer\PublicInvoiceController::class, 'show'])
                ->where('slug', 'INV-[A-Za-z0-9]{24}')
                ->name('public.invoice');

            \Illuminate\Support\Facades\Route::middleware('web')
                ->domain($mainDomain)
                ->group(base_path('routes/customer.php'));

            if ($billingDomain === '') {
                return;
            }

            \Illuminate\Support\Facades\Route::domain($billingDomain)->any('/{path?}', function (?string $path = null) use ($mainDomain) {
                $path = trim((string) $path, '/');

                $target = match (true) {
                    $path === ''                       => '/login',
                    $path === 'login'                  => '/login',
                    $path === 'dashboard'              => '/pelanggan',
                    $path === 'register-phone'         => '/pelanggan/register-phone',
                    str_starts_with($path, 'i/')       => '/'.$path,
                    str_starts_with($path, 'invoice')  => '/pelanggan/'.$path,
                    default                            => '/login',
                };

                $query = request()->getQueryString();

                return redirect()->away(
                    'https://'.$mainDomain.$target.($query ? '?'.$query : ''),
                    302,
                    ['Cache-Control' => 'no-store, private'],
                );
            })->where('path', '.*');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: []);

        $middleware->alias([
            'ensure.admin' => \App\Http\Middleware\EnsureAdmin::class,
            'role'         => \App\Http\Middleware\EnsureRole::class,
            'session.timeout' => \App\Http\Middleware\SessionTimeout::class,
            'customer.phone' => \App\Http\Middleware\EnsureCustomerPhone::class,
        ]);

        $middleware->appendToGroup('web', \App\Http\Middleware\SessionTimeout::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('web', \Illuminate\Session\Middleware\AuthenticateSession::class);

        $middleware->redirectGuestsTo(function ($request) {
            return $request->is('admin', 'admin/*')
                ? route('login')
                : route('customer.login');
        });

        $middleware->redirectUsersTo(function ($request) {
            return $request->is('admin', 'admin/*')
                ? route('dashboard')
                : route('customer.dashboard');
        });
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('wa:reminders')->hourly()->withoutOverlapping();
        $schedule->command('wa:daily-summary')->dailyAt('08:00')->withoutOverlapping();
        $schedule->command('wa:watchdog')->everyFiveMinutes()->withoutOverlapping();

        $schedule->command('logs:archive')->monthlyOn(1, '02:00')->withoutOverlapping();
        $schedule->command('logs:cleanup')->dailyAt('04:00')->withoutOverlapping();
        $schedule->command('router:log-bersihkan')->dailyAt('04:20')->withoutOverlapping();
        $schedule->command('queue:prune-failed', ['--hours' => 720])->dailyAt('04:10')->withoutOverlapping();

        $schedule->command('router:rutin')->everyFiveMinutes()->withoutOverlapping(10);
        $schedule->command('voucher:penjualan --penuh')->dailyAt('03:15')->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
    })->create();
