<?php

namespace App\Services;

use App\Jobs\SendWhatsAppImage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\AdminNotificationRecipient;
use App\Models\CustomerContact;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use App\Services\MessageTemplateService;

class BillingNotificationService
{
    private function cfg(string $key, mixed $default = null): mixed
    {
        return config("services.billing.{$key}", $default);
    }

    private function resolveTarget(?string $rawPhone, string $logContext): ?string
    {
        if (! $this->cfg('wa_notifications')) {
            return null;
        }

        if ($this->cfg('wa_test_mode')) {
            return $this->cfg('wa_test_number');
        }

        if (! $rawPhone) {
            \Log::info("billing.wa.skip.{$logContext}", ['reason' => 'no_phone']);
            return null;
        }

        return WhatsAppService::normalizePhone($rawPhone);
    }

    public function notifyAdmin(string $message, string $event = 'admin'): void
    {
        if (! $this->cfg('wa_notifications')) return;

        $targets = $this->adminTargets();

        if ($targets->isEmpty()) {
            \Log::warning("billing.wa.skip.{$event}", ['reason' => 'no_recipient']);
            return;
        }

        $sent = 0;

        foreach ($targets as $recipient) {
            if (is_string($recipient)) {
                SendWhatsAppMessage::dispatch($recipient, $message);
                $sent++;
                continue;
            }

            if (! $recipient->wants($event)) continue;

            SendWhatsAppMessage::dispatch($recipient->phone, $message);
            $sent++;
        }

        \Log::info("billing.wa.queued.{$event}", ['recipients' => $sent]);
    }

    private function adminTargets(): \Illuminate\Support\Collection
    {
        $recipients = AdminNotificationRecipient::active()->orderBy('id')->get();

        if ($recipients->isNotEmpty() || AdminNotificationRecipient::query()->exists()) {
            return $recipients;
        }

        $fallback = (string) $this->cfg('admin_notif_wa');

        return collect($fallback === '' ? [] : [$fallback]);
    }

    public function notifyCustomer(Invoice $invoice, string $message, string $logContext = 'customer'): void
    {
        $contact = CustomerContact::where('username', $invoice->username)->first();
        $target = $this->resolveTarget($contact?->phone, $logContext);

        if (! $target) return;

        SendWhatsAppMessage::dispatch($target, $message, $contact?->id);
        \Log::info("billing.wa.queued.{$logContext}", [
            'to' => $target, 'invoice' => $invoice->id, 'username' => $invoice->username,
        ]);
    }

    public function notifyCustomerImage(Invoice $invoice, string $url, string $caption, string $logContext = 'customer_image', ?string $fallbackText = null): void
    {
        $contact = CustomerContact::where('username', $invoice->username)->first();
        $target = $this->resolveTarget($contact?->phone, $logContext);

        if (! $target) return;

        SendWhatsAppImage::dispatch($target, $url, $caption, $contact?->id, null, [], $fallbackText);
        \Log::info("billing.wa.queued.{$logContext}", [
            'to' => $target, 'invoice' => $invoice->id, 'url' => $url,
        ]);
    }

    public function notifyCustomerByUsername(string $username, string $message, string $logContext = 'customer'): bool
    {
        $contact = CustomerContact::where('username', $username)->first();
        $target  = $this->resolveTarget($contact?->phone, $logContext);

        if (! $target) return false;

        SendWhatsAppMessage::dispatch($target, $message, $contact?->id);
        \Log::info("billing.wa.queued.{$logContext}", ['to' => $target, 'username' => $username]);

        return true;
    }

    public function onAccountExtended(
        string $username,
        int $days,
        CarbonInterface $until,
        ?CarbonInterface $from = null,
        ?string $paket = null,
    ): bool {
        $contact = CustomerContact::where('username', $username)->first();

        return $this->notifyCustomerByUsername(
            $username,
            MessageTemplateService::render('perpanjangan_manual', [
                'name'         => $contact?->name ?: $username,
                'username'     => $username,
                'paket'        => $paket ?: '-',
                'days'         => $days,
                'active_until' => $until->translatedFormat('d M Y'),
                'expiry_lama'  => $from?->translatedFormat('d M Y') ?? '-',
            ]),
            'account_extended',
        );
    }

    public function onRenewalRequested(string $username): void
    {
        $this->notifyAdmin(
            MessageTemplateService::render('admin_permintaan_perpanjangan', [
                'username'  => $username,
                'waktu'     => now()->translatedFormat('d M Y H:i'),
                'panel_url' => (string) config('app.url'),
            ]),
            'renewal_request',
        );
    }

    public function onInvoicePublished(Invoice $invoice): void
    {
        $portal    = $this->cfg('portal_url');
        $publicUrl = $portal . '/i/' . $invoice->slug . '?image=1';
        $contact   = \App\Models\CustomerContact::where('username', $invoice->username)->first();

        $vars = [
            'name'        => $contact?->name ?: $invoice->username,
            'username'    => $invoice->username,
            'amount'      => 'Rp ' . number_format($invoice->amount, 0, ',', '.'),
            'due_date'    => optional($invoice->due_date)->translatedFormat('d M Y') ?? '-',
            'invoice_id'  => $invoice->id,
            'invoice_url' => $portal . '/i/' . $invoice->slug,
        ];

        $this->notifyCustomerImage(
            $invoice,
            $publicUrl,
            MessageTemplateService::render('invoice_terbit', $vars),
            'invoice_published',
            MessageTemplateService::render('invoice_terbit_teks', $vars),
        );
    }

    public function onProofUploaded(Invoice $invoice): void
    {
        $this->notifyAdmin(
            MessageTemplateService::render('admin_bukti_transfer', [
                'invoice_id' => $invoice->id,
                'username'   => $invoice->username,
                'amount'     => 'Rp ' . number_format($invoice->amount, 0, ',', '.'),
                'bank'       => strtoupper(str_replace('-', ' / ', (string) $invoice->bank_to)),
                'waktu'      => now()->translatedFormat('d M Y H:i'),
                'panel_url'  => (string) config('app.url'),
            ]),
            'proof_uploaded',
        );
    }

    public function onPaymentConfirmed(Invoice $invoice): void
    {
        $portal    = $this->cfg('portal_url');
        $publicUrl = $portal . '/i/' . $invoice->slug . '?image=1';
        $contact   = \App\Models\CustomerContact::where('username', $invoice->username)->first();

        $vars = [
            'name'         => $contact?->name ?: $invoice->username,
            'username'     => $invoice->username,
            'amount'       => 'Rp ' . number_format($invoice->amount, 0, ',', '.'),
            'active_until' => optional($invoice->extended_to)->translatedFormat('d M Y') ?? '-',
            'invoice_id'   => $invoice->id,
            'invoice_url'  => $portal . '/i/' . $invoice->slug,
        ];

        $this->notifyCustomerImage(
            $invoice,
            $publicUrl,
            MessageTemplateService::render('pembayaran_diterima', $vars),
            'payment_confirmed',
            MessageTemplateService::render('pembayaran_diterima_teks', $vars),
        );
    }
}
