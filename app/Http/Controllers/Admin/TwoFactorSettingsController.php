<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorSettingsController extends Controller
{
    public function __construct(private readonly TwoFactorAuthService $twoFactor)
    {
    }

    public function index(Request $request): View
    {
        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            return view('profile.two-factor', ['user' => $user]);
        }

        $secret = $request->session()->get('2fa.pending_secret');
        if (! $secret) {
            $secret = $this->twoFactor->generateSecret();
            $request->session()->put('2fa.pending_secret', $secret);
        }

        $otpAuthUrl = $this->twoFactor->otpAuthUrl($user, $secret);
        $qrSvg      = $this->twoFactor->qrCodeSvg($otpAuthUrl);

        return view('profile.two-factor', [
            'user'   => $user,
            'secret' => $secret,
            'qrSvg'  => $qrSvg,
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.index');
        }

        $request->validate([
            'code' => ['required', 'string'],
        ], [
            'code.required' => 'Kode otentikasi wajib diisi.',
        ]);

        $secret = $request->session()->get('2fa.pending_secret');
        if (! $secret) {
            return redirect()->route('two-factor.index');
        }

        if (! $this->twoFactor->verify($secret, $request->string('code'))) {
            throw ValidationException::withMessages([
                'code' => 'Kode otentikasi tidak valid. Pastikan jam HP Anda akurat.',
            ]);
        }

        $plainCodes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret'         => $secret,
            'two_factor_recovery_codes' => json_encode($this->twoFactor->hashRecoveryCodes($plainCodes)),
            'two_factor_confirmed_at'   => now(),
        ])->save();

        $request->session()->forget('2fa.pending_secret');
        $request->session()->put('2fa.recovery_codes', $plainCodes);

        ActivityLogService::log('update', 'mengaktifkan 2FA (TOTP)', 'profile', $user->username);

        return redirect()->route('two-factor.recovery-codes');
    }

    public function recoveryCodes(Request $request): View|RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.index');
        }

        $codes = $request->session()->pull('2fa.recovery_codes');

        if (! is_array($codes) || empty($codes)) {
            return redirect()->route('two-factor.index');
        }

        return view('profile.two-factor-recovery-codes', ['codes' => $codes]);
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.index');
        }

        $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Password saat ini tidak sesuai.',
            ]);
        }

        $plainCodes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => json_encode($this->twoFactor->hashRecoveryCodes($plainCodes)),
        ])->save();

        $request->session()->put('2fa.recovery_codes', $plainCodes);

        ActivityLogService::log('update', 'regenerate recovery codes 2FA', 'profile', $user->username);

        return redirect()->route('two-factor.recovery-codes');
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Password saat ini tidak sesuai.',
            ]);
        }

        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ])->save();

        $request->session()->forget(['2fa.pending_secret', '2fa.recovery_codes']);

        ActivityLogService::log('update', 'menonaktifkan 2FA', 'profile', $user->username);

        return redirect()->route('two-factor.index')->with('success_2fa', '2FA berhasil dinonaktifkan.');
    }
}
