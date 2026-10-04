<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppImage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Services\BillingNotificationService;
use App\Services\InvoiceService;
use App\Services\MessageTemplateService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendExpiryReminders extends Command
{
    protected $signature = 'wa:reminders
        {--dry : Tampilkan target tanpa kirim}
        {--only= : Batasi ke satu username (untuk uji coba)}';
    protected $description = 'Kirim reminder H-1 + tagihan perpanjangan ke pelanggan yang Expiration di radcheck akan habis';

    public function __construct(
        private InvoiceService $invoices,
        private BillingNotificationService $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = (int) config('services.whatsapp.reminder_hours', 24);
        $now   = now();
        $from  = $now->copy()->addHours($hours - 1);
        $to    = $now->copy()->addHours($hours + 1);

        $rows = DB::table('radcheck')
            ->join('customer_contacts', 'customer_contacts.username', '=', 'radcheck.username')
            ->where('radcheck.attribute', 'Expiration')
            ->whereRaw("STR_TO_DATE(radcheck.value, '%d %b %Y %H:%i:%s') BETWEEN ? AND ?", [
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            ])
            ->where(function ($q) {
                $q->whereNull('customer_contacts.reminder_sent_at')
                  ->orWhereRaw("customer_contacts.reminder_sent_at < DATE_SUB(STR_TO_DATE(radcheck.value, '%d %b %Y %H:%i:%s'), INTERVAL 2 DAY)");
            })
            ->when($this->option('only'), fn ($q, $only) => $q->where('customer_contacts.username', $only))
            ->select(
                'customer_contacts.id as contact_id',
                'customer_contacts.username',
                'customer_contacts.name',
                'customer_contacts.phone',
                'radcheck.value as expiry_raw',
            )
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada target reminder.');
            return self::SUCCESS;
        }

        $portal     = (string) config('services.billing.portal_url');
        $waNotif    = (bool)   config('services.billing.wa_notifications', true);
        $testMode   = (bool)   config('services.billing.wa_test_mode', false);
        $testNumber = (string) config('services.billing.wa_test_number');

        $count       = 0;
        $withInvoice = 0;
        $needsManual = [];
        $sentLines   = [];

        foreach ($rows as $r) {
            $expiry  = $this->parseExpiry($r->expiry_raw);
            $expiryStr = $expiry
                ? $expiry->format('d M Y') . ' pukul ' . $expiry->format('H:i')
                : $r->expiry_raw;

            $sapaan = $r->name ?: $r->username;

            $plan = $this->planInvoice($r->username, $expiry);

            if ($plan['mode'] === 'none') {
                $needsManual[] = "• *{$r->username}* (habis {$expiry?->format('d M Y')})";
            }

            $target = $testMode
                ? $testNumber
                : \App\Services\WhatsAppService::normalizePhone($r->phone);

            if ($this->option('dry')) {
                $note    = $testMode ? " [→ test {$testNumber}]" : '';
                $tagihan = match ($plan['mode']) {
                    'existing' => 'invoice #' . $plan['invoice']->id . ' (sudah ada) Rp ' . number_format($plan['invoice']->amount, 0, ',', '.'),
                    'new'      => 'buat invoice baru Rp ' . number_format($plan['amount'], 0, ',', '.'),
                    default    => 'TANPA invoice (belum ada riwayat lunas)',
                };
                $this->line("[DRY] {$r->phone} ({$r->username}), habis {$r->expiry_raw}: {$tagihan}{$note}");
                $count++;
                $withInvoice += $plan['mode'] === 'none' ? 0 : 1;
                continue;
            }

            if (! $waNotif) {
                $this->line('[SKIP] BILLING_WA_NOTIFICATIONS=false');
                $count++;
                continue;
            }

            $invoice = $plan['mode'] === 'new'
                ? $this->invoices->createForUser($r->username, $plan['amount'], $plan['due_date'], $plan['profile'])
                : ($plan['invoice'] ?? null);

            $delay = now()->addSeconds(rand(2, 30));

            CustomerContact::where('id', $r->contact_id)->update(['reminder_sent_at' => now()]);

            if ($invoice) {
                $vars = [
                    'name'        => $sapaan,
                    'username'    => $r->username,
                    'expiry'      => $expiryStr,
                    'amount'      => 'Rp ' . number_format($invoice->amount, 0, ',', '.'),
                    'due_date'    => optional($invoice->due_date)->translatedFormat('d M Y') ?? '-',
                    'invoice_id'  => $invoice->id,
                    'invoice_url' => $portal . '/i/' . $invoice->slug,
                ];

                $caption  = MessageTemplateService::render('reminder_invoice', $vars);
                $fallback = MessageTemplateService::render('reminder_invoice_teks', $vars);

                SendWhatsAppImage::dispatch(
                    $target,
                    $portal . '/i/' . $invoice->slug . '?image=1',
                    $caption,
                    $r->contact_id,
                    null,
                    [],
                    $fallback,
                    true,
                )->delay($delay);

                $sentLines[] = "• *{$r->username}* (habis {$expiry?->format('d M Y')}) • "
                    . 'Rp ' . number_format($invoice->amount, 0, ',', '.') . " (inv #{$invoice->id})";
                $withInvoice++;
            } else {
                $msg = MessageTemplateService::render('reminder_polos', [
                    'name'     => $sapaan,
                    'username' => $r->username,
                    'expiry'   => $expiryStr,
                ]);

                SendWhatsAppMessage::dispatch($target, $msg, $r->contact_id, null, true)->delay($delay);

                $sentLines[] = "• *{$r->username}* (habis {$expiry?->format('d M Y')}) • nominal belum diketahui";
            }

            $count++;
        }

        if ($sentLines && ! $this->option('dry') && $waNotif) {
            $this->notifyAdminSummary($sentLines, $needsManual, $withInvoice);
        }

        $prefix   = $this->option('dry') ? '[DRY] ' : '';
        $modeNote = $testMode ? " (test mode → {$testNumber})" : '';
        $this->info("{$prefix}Total {$count} reminder{$modeNote}, {$withInvoice} dengan tagihan, " . count($needsManual) . ' perlu invoice manual.');

        return self::SUCCESS;
    }

    private function planInvoice(string $username, ?Carbon $expiry): array
    {
        $open = Invoice::where('username', $username)
            ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
            ->orderByDesc('id')
            ->first();

        if ($open) {
            return ['mode' => 'existing', 'invoice' => $open];
        }

        $lastPaid = Invoice::where('username', $username)
            ->where('status', Invoice::STATUS_PAID)
            ->orderByDesc('id')
            ->first();

        if (! $lastPaid) {
            return ['mode' => 'none'];
        }

        return [
            'mode'     => 'new',
            'amount'   => (int) $lastPaid->amount,
            'profile'  => $lastPaid->profile ?: InvoiceService::DEFAULT_PROFILE,
            'due_date' => ($expiry ? $expiry->copy() : now()->addDay())->endOfDay(),
        ];
    }

    private function notifyAdminSummary(array $sent, array $manual, int $withInvoice): void
    {
        $blokManual = $manual === [] ? '' : "\n⚠️ *Perlu invoice manual* (belum ada riwayat lunas):\n"
            . implode("\n", $manual) . "\n"
            . "Buat di {$this->panelUrl()}/admin/billing/create\n";

        $this->notifier->notifyAdmin(
            MessageTemplateService::render('admin_ringkasan_reminder', [
                'total'          => count($sent),
                'dengan_tagihan' => $withInvoice,
                'tanpa_tagihan'  => count($sent) - $withInvoice,
                'daftar'         => implode("\n", $sent) . "\n",
                'blok_manual'    => $blokManual,
                'waktu'          => now()->translatedFormat('d M Y H:i'),
                'panel_url'      => $this->panelUrl(),
            ]),
            'reminder_summary',
        );
    }

    private function panelUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private function parseExpiry(string $raw): ?Carbon
    {
        try {
            return Carbon::createFromFormat('d M Y H:i:s', trim($raw));
        } catch (\Throwable) {
            return null;
        }
    }
}
