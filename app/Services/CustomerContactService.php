<?php

namespace App\Services;

use App\Models\CustomerContact;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CustomerContactService
{
    public const NOMOR_REGEX = '/^(62|\+62|0)[0-9]{9,13}$/';

    public static function normalisasi(?string $mentah): ?string
    {
        $mentah = trim((string) $mentah);

        if ($mentah === '' || ! preg_match(self::NOMOR_REGEX, $mentah)) {
            return null;
        }

        return WhatsAppService::normalizePhone($mentah);
    }

    public function ubahNomor(string $username, string $nomor): array
    {
        return DB::transaction(function () use ($username, $nomor) {
            $kontak = $this->kunci($username);

            if (! $kontak) {
                try {
                    CustomerContact::create(['username' => $username, 'name' => $username, 'phone' => $nomor]);

                    return ['lama' => null, 'baru' => $nomor, 'berubah' => true, 'dibuat' => true];
                } catch (UniqueConstraintViolationException $e) {
                    $kontak = $this->kunci($username) ?? throw $e;
                }
            }

            $lama = $kontak->phone;

            if (WhatsAppService::normalizePhone((string) $lama) === $nomor) {
                return ['lama' => $lama, 'baru' => $nomor, 'berubah' => false, 'dibuat' => false];
            }

            $kontak->update(['phone' => $nomor, 'reminder_sent_at' => null]);

            return ['lama' => $lama, 'baru' => $nomor, 'berubah' => true, 'dibuat' => false];
        });
    }

    public function catatPerubahan(string $username, array $hasil, string $sumber): void
    {
        if ($hasil['dibuat']) {
            ActivityLogService::log(
                'wa_contact_create',
                "Tambah kontak WA: {$username} → {$hasil['baru']}",
                'wa_contact',
                $username,
                ['phone' => $hasil['baru'], 'sumber' => $sumber],
            );

            return;
        }

        if ($hasil['berubah']) {
            ActivityLogService::log(
                'wa_contact_update',
                "Update kontak WA: {$username}",
                'wa_contact',
                $username,
                ['phone_old' => $hasil['lama'], 'phone_new' => $hasil['baru'], 'sumber' => $sumber],
            );
        }
    }

    public function pesanSukses(string $username, array $hasil): string
    {
        if (! $hasil['berubah']) {
            return '';
        }

        $pesan = $hasil['dibuat'] ? " Nomor WA {$hasil['baru']} disimpan." : " Nomor WA diubah ke {$hasil['baru']}.";
        $lain  = $this->pemakaiLain($username, $hasil['baru']);

        return $lain === [] ? $pesan : $pesan . ' Nomor ini juga terdaftar di: ' . implode(', ', $lain) . '.';
    }

    public function pemakaiLain(string $username, string $nomor): array
    {
        return CustomerContact::whereIn('phone', [$nomor, '+' . $nomor, '0' . substr($nomor, 2)])
            ->where('username', '!=', $username)
            ->orderBy('username')
            ->limit(5)
            ->pluck('username')
            ->all();
    }

    private function kunci(string $username): ?CustomerContact
    {
        return CustomerContact::where('username', $username)->lockForUpdate()->first();
    }
}
