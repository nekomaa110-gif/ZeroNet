<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConfirmablePasswordController extends Controller
{
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $user = Auth::user();

        if (! Hash::check($request->password, $user->password)) {
            ActivityLogService::log(
                action: 'password_confirm_failed',
                description: "Konfirmasi password gagal untuk: {$user->username}",
                subjectType: 'user',
                subjectId: (string) $user->id,
                properties: ['ip' => $request->ip()],
                userId: $user->id,
            );

            throw ValidationException::withMessages([
                'password' => 'Password tidak sesuai.',
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function verify(Request $request): JsonResponse
    {
        $pwd = (string) $request->input('password', '');
        $intent = trim((string) $request->input('intent', ''));
        $intent = mb_substr($intent, 0, 80);
        $user = Auth::user();

        if ($pwd === '' || ! Hash::check($pwd, $user->password)) {
            $desc = $intent !== ''
                ? 'Verifikasi password gagal, tidak bisa ' . lcfirst($intent)
                : 'Verifikasi password gagal (tanpa konteks aksi)';

            ActivityLogService::log(
                action: 'password_confirm_failed',
                description: $desc,
                subjectType: 'user',
                subjectId: (string) $user->id,
                properties: ['ip' => $request->ip(), 'intent' => $intent],
                userId: $user->id,
            );

            return response()->json(['ok' => false, 'message' => 'Password salah.'], 422);
        }

        return response()->json(['ok' => true]);
    }
}
