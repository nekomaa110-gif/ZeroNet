<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AdminProfileController extends Controller
{
    public function edit(): View
    {
        return view('profile.edit', ['user' => Auth::user()]);
    }

    public function updateInfo(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:64', 'alpha_dash',
                           'unique:users,username,' . $user->id],
        ], [
            'name.required'      => 'Nama wajib diisi.',
            'username.required'  => 'Username wajib diisi.',
            'username.alpha_dash'=> 'Username hanya boleh huruf, angka, strip, dan underscore.',
            'username.unique'    => 'Username sudah digunakan.',
        ]);

        $user->update($validated);

        ActivityLogService::log('update', 'mengupdate profil: info akun', 'profile', $user->username);

        return back()->with('success_info', 'Informasi akun berhasil diperbarui.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'confirmed', Password::min(8)],
        ], [
            'current_password.required' => 'Password saat ini wajib diisi.',
            'password.required'         => 'Password baru wajib diisi.',
            'password.confirmed'        => 'Konfirmasi password tidak cocok.',
            'password.min'              => 'Password minimal 8 karakter.',
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            return back()
                ->withErrors(['current_password' => 'Password saat ini tidak sesuai.'])
                ->withInput();
        }

        $user->update(['password' => $request->password]);

        Auth::logoutOtherDevices($request->password);

        ActivityLogService::log('update', 'mengupdate profil: password (logout perangkat lain dipaksa)', 'profile', $user->username);

        return back()->with('success_password', 'Password berhasil diperbarui. Sesi di perangkat lain sudah dilogout.');
    }
}
