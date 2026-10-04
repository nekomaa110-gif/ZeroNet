<?php

namespace App\Http\Requests\Admin;

use App\Models\RadCheck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RadiusUserRequest extends FormRequest
{
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
                'max:64',
                'regex:/^[a-zA-Z0-9._\-@]+$/',

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
            'group'  => ['nullable', 'string', 'max:64'],
            'expiry' => ['nullable', 'date_format:Y-m-d'],
            'invoice_aksi' => ['nullable', Rule::in(['lunas', 'batal'])],
            'invoice_id'   => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required'     => 'Username wajib diisi.',
            'username.regex'        => 'Username hanya boleh huruf, angka, titik, strip, dan @.',
            'username.max'          => 'Username maksimal 64 karakter.',
            'password.required'     => 'Password wajib diisi.',
            'password.min'          => 'Password tidak boleh kosong.',
            'expiry.date_format'    => 'Tanggal expire tidak valid.',
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
