<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerContact;
use App\Services\ActivityLogService;
use App\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PhoneController extends Controller
{
    public function show()
    {
        $user = Auth::guard('customer')->user();
        $contact = CustomerContact::where('username', $user->getAuthIdentifier())->first();

        if ($contact && !empty($contact->phone)) {
            return redirect()->route('customer.dashboard');
        }

        return view('customer.register-phone', [
            'username' => $user->getAuthIdentifier(),
            'businessWa' => (string) config('services.billing.business_wa'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::guard('customer')->user();
        $username = $user->getAuthIdentifier();

        $data = $request->validate([
            'phone' => ['required', 'string', 'min:9', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'name'  => ['nullable', 'string', 'max:100'],
        ], [
            'phone.required' => 'Nomor HP wajib diisi.',
            'phone.regex'    => 'Format nomor HP tidak valid.',
            'phone.min'      => 'Nomor HP terlalu pendek.',
        ]);

        $normalized = WhatsAppService::normalizePhone($data['phone']);

        if (strlen($normalized) < 11 || strlen($normalized) > 15) {
            return back()->withErrors(['phone' => 'Nomor HP tidak valid (harus 11-15 digit setelah dinormalisasi).'])->withInput();
        }

        CustomerContact::updateOrCreate(
            ['username' => $username],
            [
                'phone' => $normalized,
                'name'  => $data['name'] ?: $username,
            ]
        );

        ActivityLogService::log(
            action: 'customer_phone_register',
            description: "Pelanggan daftar nomor HP: {$username} → {$normalized}",
            subjectType: 'customer',
            subjectId: $username,
            properties: ['phone' => $normalized, 'name' => $data['name'] ?? null, 'ip' => $request->ip()],
        );

        return redirect()->route('customer.dashboard')
            ->with('success', 'Nomor HP berhasil disimpan. Notifikasi tagihan akan dikirim ke nomor ini.');
    }
}
