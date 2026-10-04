<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $timeout = config('session.lifetime') * 60;
        $lastActivity = session('last_activity_time');

        if ($lastActivity && (time() - $lastActivity) > $timeout) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Sesi Anda telah berakhir. Silakan login kembali.',
            ]);
        }

        session(['last_activity_time' => time()]);

        return $next($request);
    }
}
