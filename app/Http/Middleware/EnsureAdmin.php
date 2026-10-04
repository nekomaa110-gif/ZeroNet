<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        if (! $request->user()->is_active) {
            auth()->logout();
            return redirect()->route('login')->withErrors([
                'email' => 'Akun Anda telah dinonaktifkan.',
            ]);
        }

        if (! in_array($request->user()->role, ['admin', 'operator'])) {
            abort(403, 'Akses ditolak.');
        }

        if ($this->mustSetUpTwoFactor($request)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Aktifkan 2FA dulu sebelum memakai panel.',
                ], 403);
            }

            return redirect()->route('two-factor.index');
        }

        return $next($request);
    }

    private function mustSetUpTwoFactor(Request $request): bool
    {
        $user = $request->user();

        return ! $user->hasTwoFactorEnabled()
            && in_array($user->role, (array) config('auth.two_factor_required_roles', []), true)
            && ! $request->routeIs('two-factor.index', 'two-factor.confirm', 'two-factor.recovery-codes');
    }
}
