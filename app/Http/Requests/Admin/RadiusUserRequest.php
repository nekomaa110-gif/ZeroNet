<?php

namespace App\Http\Requests\Admin;

use App\Models\RadCheck;
use App\Services\CustomerContactService;
use App\Services\CustomerPriceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RadiusUserRequest extends FormRequest
{
    public const USERNAME_BARU_REGEX = '/^[A-Za-z0-9_-]+$/';
    public const USERNAME_BARU_MAKS = 50;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate  = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $username  = $this->route('radius_user');

        return [
            'username' => [
                'required',
                'string',
                $isUpdate ? 'max:64' : 'max:' . self::USERNAME_BARU_MAKS,
                $isUpdate ? 'regex:/^[a-zA-Z0-9._\-@]+$/' : 'regex:' . self::USERNAME_BARU_REGEX,

                $isUpdate ? Rule::exists('radcheck', 'username') : function ($attr, $val, $fail) {
                    if (RadCheck::where('username', $val)->exists()) {
                        $fail('Username sudah digunakan.');
                    }
                },
            ],
            'password' => [
                $isUpdate ? 'nullable' : 'required',
                'string',
                'min:1',
                'max:128',
            ],
            'kirim_voucher' => ['nullable', Rule::in(['manual', 'otomatis'])],
            'phone'  => [
                $isUpdate ? 'nullable' : 'required',
                'string',
                'max:25',
                function ($attr, $val, $fail) {
                    if (CustomerContactService::normalisasi($val) === null) {
                        $fail('Nomor harus format Indonesia: 0812..., 62812..., atau +62812...');
                    }
                },
            ],
            'group'  => ['nullable', 'string', 'max:64'],
            'expiry' => ['nullable', 'date_format:Y-m-d'],
            'invoice_aksi' => ['nullable', Rule::in(['lunas', 'batal'])],
            'invoice_id'   => ['nullable', 'integer'],
            'kembali'      => ['nullable', 'array'],
        ] + $this->aturanHarga();
    }

    private function aturanHarga(): array
    {
        if (! $this->user()?->isAdmin()) {
            return [];
        }

        $username = $this->route('radius_user') ?? $this->input('username');
        $username = is_string($username) ? $username : '';

        if (app(CustomerPriceService::class)->isGratis($username)) {
            return [];
        }

        return [
            'price' => [
                'nullable',
                function ($attr, $val, $fail) {
                    if (CustomerPriceService::normalisasi($val) === false) {
                        $fail(CustomerPriceService::PESAN_GALAT);
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'username.required'     => 'Username wajib diisi.',
            'username.regex'        => $isUpdate ? 'Username hanya boleh huruf, angka, titik, strip, dan @.' : 'Username hanya boleh huruf, angka, - dan _.',
            'username.max'          => $isUpdate ? 'Username maksimal 64 karakter.' : 'Username maksimal ' . self::USERNAME_BARU_MAKS . ' karakter.',
            'password.required'     => 'Password wajib diisi.',
            'password.min'          => 'Password tidak boleh kosong.',
            'expiry.date_format'    => 'Tanggal expire tidak valid.',
            'phone.required'        => 'Nomor WhatsApp wajib diisi.',
            'kirim_voucher.in'      => 'Pilihan kirim voucher tidak dikenal.',
        ];
    }

    public function attributes(): array
    {
        return [
            'username' => 'username',
            'password' => 'password',
            'group'    => 'profil/paket',
            'expiry'   => 'tanggal expire',
        ];
    }
}
