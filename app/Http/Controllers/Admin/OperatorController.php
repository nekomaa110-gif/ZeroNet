<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class OperatorController extends Controller
{
    public function index(): View
    {
        $users = User::orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('username')
            ->get();

        return view('operators.index', ['users' => $users]);
    }

    public function create(): View
    {
        return view('operators.form', [
            'user' => new User(['role' => 'operator', 'is_active' => true]),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'username'  => ['required', 'string', 'max:64', 'alpha_dash', 'unique:users,username'],
            'email'     => ['required', 'email', 'max:160', 'unique:users,email'],
            'role'      => ['required', Rule::in(['admin', 'operator'])],
            'is_active' => ['nullable', 'boolean'],
            'password'  => ['required', 'string', 'confirmed', Password::min(8)],
        ], $this->messages());

        $user = User::create([
            'name'      => $validated['name'],
            'username'  => $validated['username'],
            'email'     => $validated['email'],
            'role'      => $validated['role'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'password'  => $validated['password'],
        ]);

        ActivityLogService::log('create', "membuat operator: {$user->username} ({$user->role})", 'operator', $user->username);

        return redirect()->route('operators.index')->with('success', "Operator {$user->username} berhasil dibuat.");
    }

    public function edit(User $user): View
    {
        return view('operators.form', [
            'user' => $user,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $isSelf = $user->id === Auth::id();

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'username'  => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email'     => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'role'      => ['required', Rule::in(['admin', 'operator'])],
            'is_active' => ['nullable', 'boolean'],
            'password'  => ['nullable', 'string', 'confirmed', Password::min(8)],
        ], $this->messages());

        if ($isSelf && $validated['role'] !== 'admin') {
            return back()->withErrors(['role' => 'Tidak bisa menurunkan role akun sendiri.'])->withInput();
        }
        if ($isSelf && ! $request->boolean('is_active')) {
            return back()->withErrors(['is_active' => 'Tidak bisa menonaktifkan akun sendiri.'])->withInput();
        }

        $payload = [
            'name'      => $validated['name'],
            'username'  => $validated['username'],
            'email'     => $validated['email'],
            'role'      => $validated['role'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        $user->update($payload);

        ActivityLogService::log('update', "mengupdate operator: {$user->username}", 'operator', $user->username);

        return redirect()->route('operators.index')->with('success', "Operator {$user->username} berhasil diupdate.");
    }

    public function toggle(User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('forbidden', 'Tidak bisa menonaktifkan akun sendiri.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        $status = $user->is_active ? 'mengaktifkan' : 'menonaktifkan';
        ActivityLogService::log('update', "{$status} operator: {$user->username}", 'operator', $user->username);

        return back()->with('success', "Operator {$user->username} " . ($user->is_active ? 'diaktifkan.' : 'dinonaktifkan.'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('forbidden', 'Tidak bisa menghapus akun sendiri.');
        }

        $pwd = (string) $request->input('current_password', '');

        if ($pwd === '' || ! Hash::check($pwd, Auth::user()->password)) {
            ActivityLogService::log(
                'password_confirm_failed',
                "Konfirmasi password gagal saat hapus operator: {$user->username}",
                'operator',
                $user->username,
            );

            return back()->with('forbidden', "Password salah. Operator {$user->username} TIDAK dihapus.");
        }

        $username = $user->username;
        $user->delete();

        ActivityLogService::log('delete', "menghapus operator: {$username}", 'operator', $username);

        return redirect()->route('operators.index')->with('success', "Operator {$username} berhasil dihapus.");
    }

    private function messages(): array
    {
        return [
            'name.required'      => 'Nama wajib diisi.',
            'username.required'  => 'Username wajib diisi.',
            'username.alpha_dash'=> 'Username hanya boleh huruf, angka, strip, dan underscore.',
            'username.unique'    => 'Username sudah digunakan.',
            'email.required'     => 'Email wajib diisi.',
            'email.email'        => 'Format email tidak valid.',
            'email.unique'       => 'Email sudah digunakan.',
            'role.required'      => 'Role wajib dipilih.',
            'role.in'            => 'Role tidak valid.',
            'password.required'  => 'Password wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.min'       => 'Password minimal 8 karakter.',
        ];
    }
}
