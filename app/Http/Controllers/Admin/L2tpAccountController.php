<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\L2tpAccount;
use App\Services\ActivityLogService;
use App\Services\L2tpAccountService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class L2tpAccountController extends Controller
{
    public function index()
    {
        $accounts = L2tpAccount::orderBy('username')->get();
        $pool     = L2tpAccountService::pool();

        return view('l2tp.index', [
            'accounts'    => $accounts,
            'suggestedIp' => $pool['free'][0] ?? null,
            'pool'        => $pool,
            'localIp'     => config('l2tp.local_ip'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(null), $this->messages());

        try {
            L2tpAccountService::assertIpAllowed($data['remote_ip']);
        } catch (Exception $e) {
            throw ValidationException::withMessages(['remote_ip' => $e->getMessage()]);
        }

        try {
            $account = L2tpAccountService::create([
                'username'   => $data['username'],
                'secret'     => $data['secret'],
                'remote_ip'  => $data['remote_ip'],
                'note'       => $data['note'] ?? null,
                'is_active'  => true,
                'created_by' => $request->user()?->id,
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        ActivityLogService::log(
            action: 'create',
            description: "menambah akun L2TP: {$account->username} ({$account->remote_ip})",
            subjectType: 'l2tp_account',
            subjectId: (string) $account->id,
        );

        return response()->json([
            'success' => true,
            'message' => "Akun L2TP \"{$account->username}\" berhasil ditambahkan.",
        ]);
    }

    public function update(Request $request, L2tpAccount $l2tp_account)
    {
        $data = $request->validate($this->rules($l2tp_account), $this->messages());

        try {
            L2tpAccountService::assertIpAllowed($data['remote_ip'], $l2tp_account->id);
        } catch (Exception $e) {
            throw ValidationException::withMessages(['remote_ip' => $e->getMessage()]);
        }

        $payload = [
            'username'  => $data['username'],
            'remote_ip' => $data['remote_ip'],
            'note'      => $data['note'] ?? null,
        ];

        if (! empty($data['secret'])) {
            $payload['secret'] = $data['secret'];
        }

        try {
            L2tpAccountService::update($l2tp_account, $payload);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        ActivityLogService::log(
            action: 'update',
            description: "mengubah akun L2TP: {$l2tp_account->username} ({$l2tp_account->remote_ip})",
            subjectType: 'l2tp_account',
            subjectId: (string) $l2tp_account->id,
            properties: ['password_changed' => ! empty($data['secret'])],
        );

        return response()->json([
            'success' => true,
            'message' => "Akun L2TP \"{$l2tp_account->username}\" berhasil diperbarui.",
        ]);
    }

    public function toggle(L2tpAccount $l2tp_account)
    {
        try {
            L2tpAccountService::toggle($l2tp_account);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        ActivityLogService::log(
            action: 'update',
            description: ($l2tp_account->is_active ? 'mengaktifkan' : 'menonaktifkan')." akun L2TP: {$l2tp_account->username}",
            subjectType: 'l2tp_account',
            subjectId: (string) $l2tp_account->id,
        );

        return response()->json([
            'success'   => true,
            'is_active' => $l2tp_account->is_active,
            'message'   => "Akun \"{$l2tp_account->username}\" ".($l2tp_account->is_active ? 'diaktifkan' : 'dinonaktifkan').'.',
        ]);
    }

    public function destroy(L2tpAccount $l2tp_account)
    {
        $username = $l2tp_account->username;

        try {
            L2tpAccountService::delete($l2tp_account);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        ActivityLogService::log(
            action: 'delete',
            description: "menghapus akun L2TP: {$username}",
            subjectType: 'l2tp_account',
            subjectId: (string) $l2tp_account->id,
        );

        return response()->json([
            'success' => true,
            'message' => "Akun L2TP \"{$username}\" dihapus.",
        ]);
    }

    private function rules(?L2tpAccount $account): array
    {
        return [
            'username'  => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._@-]+$/', Rule::unique('l2tp_accounts', 'username')->ignore($account?->id)],
            'secret'    => [$account ? 'nullable' : 'required', 'string', 'max:64', 'regex:/^[^\s"#\\\\@][^\s"#\\\\]*$/'],
            'remote_ip' => ['required', 'ip'],
            'note'      => ['nullable', 'string', 'max:100'],
        ];
    }

    private function messages(): array
    {
        return [
            'username.required' => 'Username wajib diisi.',
            'username.regex'    => 'Username hanya boleh huruf, angka, titik, garis bawah, strip, dan @.',
            'username.unique'   => 'Sudah ada akun L2TP dengan username itu.',
            'secret.required'   => 'Password wajib diisi.',
            'secret.regex'      => 'Password tidak boleh mengandung spasi, tanda kutip, #, atau backslash, dan tidak boleh diawali @.',
            'remote_ip.required' => 'IP tunnel wajib diisi.',
            'remote_ip.ip'      => 'IP tidak valid.',
        ];
    }
}
