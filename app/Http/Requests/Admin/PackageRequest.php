<?php

namespace App\Http\Requests\Admin;

use App\Services\PackageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        $groupnameRules = [
            'required', 'string', 'max:64',
            'regex:/^[a-zA-Z0-9_\-\.]+$/',
            function ($attr, $val, $fail) {
                if (app(PackageService::class)->exists($val)) {
                    $fail('Nama paket sudah digunakan.');
                }
            },
        ];

        return [
            'groupname'                 => $isUpdate ? ['sometimes'] : $groupnameRules,
            'display_name'              => ['nullable', 'string', 'max:100'],
            'speed_label'               => ['nullable', 'string', 'max:50'],
            'description'               => ['nullable', 'string', 'max:255'],
            'is_active'                 => ['nullable', 'boolean'],

            'price'                     => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'show_price'                => ['nullable', 'boolean'],
            'validity_days'             => ['nullable', 'integer', 'min:1', 'max:3650'],
            'public_description'        => ['nullable', 'string', 'max:255'],
            'is_public'                 => ['nullable', 'boolean'],
            'sort_order'                => ['nullable', 'integer', 'min:0', 'max:999'],
            'attributes'                => ['nullable', 'array', 'max:50'],
            'attributes.*.attribute'    => [
                'required_with:attributes', 'string', 'max:64',
                Rule::notIn(PackageService::TIME_BLOCKED),
            ],
            'attributes.*.op'           => ['required_with:attributes', Rule::in(PackageService::OPERATORS)],
            'attributes.*.value'        => ['required_with:attributes', 'string', 'max:255'],
            'attributes.*.target_table' => ['required_with:attributes', Rule::in(PackageService::TARGET_TABLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'groupname.required'                      => 'Nama paket wajib diisi.',
            'groupname.regex'                         => 'Nama paket hanya boleh huruf, angka, strip, titik, dan underscore.',
            'attributes.*.attribute.required_with'    => 'Nama atribut wajib diisi.',
            'attributes.*.attribute.not_in'           => 'Atribut :input dikelola otomatis oleh Tipe Voucher dan tidak boleh ada di Paket.',
            'attributes.*.op.required_with'           => 'Operator wajib dipilih.',
            'attributes.*.value.required_with'        => 'Nilai atribut wajib diisi.',
            'attributes.*.target_table.required_with' => 'Target tabel wajib dipilih.',
        ];
    }
}
