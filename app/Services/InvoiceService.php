<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\RadCheck;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public const DEFAULT_DAYS = 30;

    public const DEFAULT_PROFILE = 'Member';

    public function createForUser(string $username, int $amount, Carbon $dueDate, string $profile = self::DEFAULT_PROFILE, ?string $notes = null): Invoice
    {
        return Invoice::create([
            'username' => $username,
            'profile' => $profile,
            'amount' => $amount,
            'status' => Invoice::STATUS_UNPAID,
            'due_date' => $dueDate,
            'notes' => $notes,
        ]);
    }

    public function publish(Invoice $invoice, int $amount, Carbon $dueDate, ?string $notes = null): Invoice
    {
        $invoice->update([
            'amount' => $amount,
            'due_date' => $dueDate,
            'status' => Invoice::STATUS_UNPAID,
            'notes' => $notes ?? $invoice->notes,
        ]);

        return $invoice->fresh();
    }

    public function confirmPayment(Invoice $invoice, int $adminId, int $days = self::DEFAULT_DAYS): ?Invoice
    {
        return DB::transaction(function () use ($invoice, $adminId, $days) {
            $invoice = Invoice::whereKey($invoice->getKey())->lockForUpdate()->first();

            if (! $invoice || ! in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_UNPAID], true)) {
                return null;
            }

            $now = Carbon::now();
            $newExpiry = $now->copy()->addDays($days)->setTime(23, 59, 59);

            $this->setExpiration($invoice->username, $newExpiry);

            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                'confirmed_at' => $now,
                'confirmed_by' => $adminId,
                'extended_to' => $newExpiry,
            ]);

            return $invoice->fresh();
        });
    }

    public function tutupLewatPerpanjang(Invoice $invoice, string $aksi, int $adminId, ?Carbon $sampai, string $jalur = 'Perpanjang manual'): Invoice
    {
        if ($aksi === 'batal') {
            return $this->cancel($invoice, "masa aktif diperpanjang lewat {$jalur} tanpa pembayaran invoice ini");
        }

        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => $adminId,
            'extended_to' => $sampai,
            'notes' => trim(($invoice->notes ? $invoice->notes . "\n" : '') . "[LUNAS] dibayar di luar invoice, ditutup lewat {$jalur}"),
        ]);

        return $invoice->fresh();
    }

    public function cancel(Invoice $invoice, ?string $reason = null): Invoice
    {
        $invoice->update([
            'status' => Invoice::STATUS_CANCELLED,
            'notes' => $reason
                ? trim(($invoice->notes ? $invoice->notes . "\n" : '') . '[BATAL] ' . $reason)
                : $invoice->notes,
        ]);

        return $invoice->fresh();
    }

    private function setExpiration(string $username, Carbon $datetime): void
    {
        $value = $datetime->format('d M Y H:i:s');

        $row = RadCheck::where('username', $username)
            ->where('attribute', 'Expiration')
            ->first();

        if ($row) {
            $row->update(['value' => $value, 'op' => ':=']);
        } else {
            RadCheck::create([
                'username' => $username,
                'attribute' => 'Expiration',
                'op' => ':=',
                'value' => $value,
            ]);
        }

        RadiusUserService::flushStatsCache();
    }
}
