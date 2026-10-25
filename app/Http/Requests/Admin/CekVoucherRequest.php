<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CekVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->post();
    }

    protected function prepareForValidation(): void
    {
        $sumber = $this->getInputSource();

        if ($sumber->has('kartu') && self::kartuKosong($sumber->all()['kartu'] ?? null)) {
            $sumber->remove('kartu');
        }

        foreach (['dari', 'sampai'] as $ujung) {
            $isi = $sumber->all()[$ujung] ?? null;

            if (is_array($isi) && array_key_exists('kartu', $isi) && self::kartuKosong($isi['kartu'])) {
                unset($isi['kartu']);
                $sumber->set($ujung, $isi);
            }
        }
    }

    public function rules(): array
    {
        if ($this->routeIs('voucher-check.rentang')) {
            $ujung = fn (string $u) => [
                $u                  => ['required', 'array'],
                "{$u}.no"           => ['nullable', 'integer', 'min:1', 'max:1000'],
                "{$u}.username"     => ['nullable', 'string', 'max:64'],
                "{$u}.password"     => ['nullable', 'string', 'max:64'],
                "{$u}.kartu"        => ['nullable', 'array'],
                "{$u}.kartu.sumber" => ["required_with:{$u}.kartu", 'in:hidup,terhapus'],
                "{$u}.kartu.id"     => ["required_with:{$u}.kartu", 'integer'],
            ];

            return [
                ...$ujung('dari'),
                ...$ujung('sampai'),
                'batch'  => ['nullable', 'string', 'max:64'],
                'router' => ['nullable', 'string', 'max:64'],
            ];
        }

        return [
            'no'           => ['nullable', 'integer', 'min:1', 'max:1000'],
            'username'     => ['nullable', 'string', 'max:64'],
            'password'     => ['nullable', 'string', 'max:64'],
            'batch'        => ['nullable', 'string', 'max:64'],
            'router'       => ['nullable', 'string', 'max:64'],
            'kartu'        => ['nullable', 'array'],
            'kartu.sumber' => ['required_with:kartu', 'in:hidup,terhapus'],
            'kartu.id'     => ['required_with:kartu', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'no.min'       => 'No kartu harus 1 sampai 1000.',
            'no.max'       => 'No kartu harus 1 sampai 1000.',
            'no.integer'   => 'No kartu harus angka.',
            '*.no.min'     => 'No kartu harus 1 sampai 1000.',
            '*.no.max'     => 'No kartu harus 1 sampai 1000.',
            '*.no.integer' => 'No kartu harus angka.',
            '*.max'        => 'Maksimal 64 karakter.',
        ];
    }

    public function withValidator($validator): void
    {
        if (! $this->routeIs('voucher-check.rentang')) {
            return;
        }

        $validator->after(function ($v) {
            $data  = $this->validationData();
            $batch = trim((string) ($data['batch'] ?? ''));

            foreach (['dari' => 'Kode awal', 'sampai' => 'Kode akhir'] as $kunci => $nama) {
                $u = $data[$kunci] ?? null;

                if (! is_array($u) || $v->errors()->has($kunci)) {
                    continue;
                }

                $ada = trim((string) ($u['username'] ?? '')) !== ''
                    || self::kartu($u['kartu'] ?? null) !== null
                    || (filled($u['no'] ?? null) && $batch !== '');

                if (! $ada) {
                    $v->errors()->add($kunci, "{$nama}: isi Username, atau No bila Batch diisi.");
                }
            }
        });
    }

    public static function kartu(?array $kartu): ?array
    {
        return self::kartuKosong($kartu) ? null : ['sumber' => (string) $kartu['sumber'], 'id' => (int) $kartu['id']];
    }

    private static function kartuKosong(mixed $kartu): bool
    {
        return ! is_array($kartu) || blank($kartu['sumber'] ?? null) || blank($kartu['id'] ?? null);
    }
}
