<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Support\PerangkatDikenal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        $wa = preg_replace('/\D+/', '', (string) config('services.billing.business_wa'));

        return view('customer.login', [
            'businessWa' => $wa,
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:253'],
        ]);

        $key     = 'cust-login:' . sha1($request->ip() . '|' . strtolower($data['username']));
        $userKey = 'cust-login-user:' . sha1(strtolower($data['username']));

        $dikenal  = PerangkatDikenal::dikenal($request, PerangkatDikenal::PELANGGAN, $data['username']);
        $limiters = $dikenal ? [$key => 5] : [$key => 5, $userKey => 20];

        foreach ($limiters as $limiter => $max) {
            if (RateLimiter::tooManyAttempts($limiter, $max)) {
                $seconds = RateLimiter::availableIn($limiter);
                throw ValidationException::withMessages([
                    'throttle' => "Terlalu banyak percobaan. Coba lagi {$seconds} detik.",
                ]);
            }
        }

        if (! Auth::guard('customer')->attempt($data, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            if (! $dikenal) {
                RateLimiter::hit($userKey, 3600);
            }
            throw ValidationException::withMessages([
                'credentials' => 'Username atau password salah.',
            ]);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($userKey);
        PerangkatDikenal::tandai($request, PerangkatDikenal::PELANGGAN, $data['username']);
        $request->session()->regenerate();
        $request->session()->forget('url.intended');

        return redirect()->route('customer.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('customer.login');
    }
}
