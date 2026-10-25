<?php

namespace App\Services;

use App\Models\CustomerContact;
use App\Models\CustomerPrice;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\RadCheck;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

class CustomerPriceService
{
    public const MIN = 1000;
    public const MAX = 99999999;
    public const PESAN_GALAT = 'Harga harus antara Rp1.000 dan Rp99.999.999, atau kosong.';

    public static function normalisasi(mixed $mentah): int|null|false
    {
        if ($mentah === null) {
            return null;
        }

        if (! is_string($mentah) && ! is_int($mentah)) {
            return false;
        }

        $teks = trim((string) $mentah);

        if ($teks === '') {
            return null;
        }

        if (! preg_match('/^\d{1,3}(\.\d{3})+$/', $teks) && ! preg_match('/^\d+$/', $teks)) {
            return false;
        }

        $digit = ltrim(str_replace('.', '', $teks), '0');

        if ($digit === '') {
            return null;
        }

        if (strlen($digit) > 8) {
            return false;
        }

        $angka = (int) $digit;

        return self::sah($angka) ? $angka : false;
    }

    public static function sah(?int $harga): bool
    {
        return $harga !== null && $harga >= self::MIN && $harga <= self::MAX;
    }

    public static function rupiah(int $n): string
    {
        return 'Rp' . number_format($n, 0, ',', '.');
    }

    public function daftarGratis(): array
    {
        return array_values(array_filter(array_map(
            fn ($u) => mb_strtolower(trim((string) $u)),
            (array) config('services.billing.akun_gratis', [])
        )));
    }

    public function isGratis(string $username): bool
    {
        return in_array(mb_strtolower($username), $this->daftarGratis(), true);
    }

    public function harga(string $username): ?int
    {
        return CustomerPrice::where('username', $username)->value('price');
    }

    public function hargaBanyak(iterable $usernames): array
    {
        $daftar = collect($usernames)->filter()->values();

        if ($daftar->isEmpty()) {
            return [];
        }

        return CustomerPrice::whereIn('username', $daftar)
            ->get(['username', 'price'])
            ->mapWithKeys(fn (CustomerPrice $p) => [mb_strtolower($p->username) => $p->price])
            ->all();
    }

    public function hargaPaket(?string $group): ?int
    {
        if ($group === null || $group === '') {
            return null;
        }

        $harga = Package::where('groupname', $group)->value('price');

        return self::sah($harga === null ? null : (int) $harga) ? (int) $harga : null;
    }

    public function petaHargaPaket(iterable $groups): array
    {
        $daftar = collect($groups)->values();
        $harga  = Package::whereIn('groupname', $daftar)
            ->get(['groupname', 'price'])
            ->mapWithKeys(fn (Package $p) => [mb_strtolower($p->groupname) => $p->price]);

        return $daftar->mapWithKeys(function ($g) use ($harga) {
            $n = $harga->get(mb_strtolower((string) $g));

            return [$g => self::sah($n === null ? null : (int) $n) ? (int) $n : null];
        })->all();
    }

    public function simpan(string $username, ?int $harga, ?int $aktorId): array
    {
        $baris = CustomerPrice::where('username', $username)->exists() ? $this->kunci($username) : null;
        $lama  = $baris?->price;

        if ($lama === $harga) {
            return ['lama' => $lama, 'baru' => $harga, 'berubah' => false];
        }

        if ($harga === null) {
            $baris->delete();

            return ['lama' => $lama, 'baru' => null, 'berubah' => true];
        }

        if (! $baris) {
            try {
                CustomerPrice::create(['username' => $username, 'price' => $harga, 'updated_by' => $aktorId]);

                return ['lama' => null, 'baru' => $harga, 'berubah' => true];
            } catch (UniqueConstraintViolationException $e) {
                $baris = $this->kunci($username) ?? throw $e;
                $lama  = $baris->price;

                if ($lama === $harga) {
                    return ['lama' => $lama, 'baru' => $harga, 'berubah' => false];
                }
            }
        }

        $baris->update(['price' => $harga, 'updated_by' => $aktorId]);

        return ['lama' => $lama, 'baru' => $harga, 'berubah' => true];
    }

    public function catatPerubahan(string $username, array $hasil): void
    {
        if (! $hasil['berubah']) {
            return;
        }

        $uraian = $hasil['baru'] === null
            ? "Hapus harga user: {$username}"
            : "Ubah harga user: {$username} → " . self::rupiah($hasil['baru']);

        ActivityLogService::log(
            'user_price_update',
            $uraian,
            'radius_user',
            $username,
            ['price_old' => $hasil['lama'], 'price_new' => $hasil['baru']],
        );
    }

    public function pesanSukses(array $hasil): string
    {
        if (! $hasil['berubah']) {
            return '';
        }

        return $hasil['baru'] === null ? ' Harga dihapus.' : ' Harga diubah ke ' . self::rupiah($hasil['baru']) . '.';
    }

    public function saran(string $username): ?array
    {
        $lunas = Invoice::where('username', $username)
            ->where('status', Invoice::STATUS_PAID)
            ->whereBetween('amount', [self::MIN, self::MAX])
            ->orderByDesc('id')
            ->first(['id', 'amount', 'paid_at', 'confirmed_at', 'created_at']);

        if (! $lunas) {
            return null;
        }

        return [
            'amount'     => (int) $lunas->amount,
            'invoice_id' => (int) $lunas->id,
            'tanggal'    => $lunas->paid_at ?? $lunas->confirmed_at ?? $lunas->created_at,
        ];
    }

    public function siklusTerlewat(string $username): ?Carbon
    {
        $terkirim = CustomerContact::where('username', $username)->value('reminder_sent_at');

        if (! $terkirim) {
            return null;
        }

        $mentah = RadCheck::where('username', $username)->where('attribute', 'Expiration')->value('value');

        try {
            $expire = $mentah ? Carbon::createFromFormat('d M Y H:i:s', trim($mentah)) : null;
        } catch (\Throwable) {
            $expire = null;
        }

        if (! $expire || Carbon::parse($terkirim)->lt($expire->copy()->subDays(2))) {
            return null;
        }

        $terbuka = Invoice::where('username', $username)
            ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
            ->exists();

        return $terbuka ? null : $expire;
    }

    private function kunci(string $username): ?CustomerPrice
    {
        return CustomerPrice::where('username', $username)->lockForUpdate()->first();
    }
}
