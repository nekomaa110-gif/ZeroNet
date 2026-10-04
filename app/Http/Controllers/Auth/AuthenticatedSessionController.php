<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\PerangkatDikenal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->validateCredentials();

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('login.2fa', [
                'user_id'  => $user->id,
                'remember' => $request->boolean('remember'),
                'expires'  => now()->addMinutes(5)->timestamp,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        PerangkatDikenal::tandai($request, PerangkatDikenal::ADMIN, $user->username);

        $request->session()->regenerate();
        session(['last_activity_time' => time()]);

        $user->update(['last_login_at' => now()]);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
