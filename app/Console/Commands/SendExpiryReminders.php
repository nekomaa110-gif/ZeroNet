<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppImage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\RadUserGroup;
use App\Services\CustomerPriceService;
use App\Services\BillingNotificationService;
use App\Services\InvoiceService;
use App\Services\MessageTemplateService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendExpiryReminders extends Command
{
    protected $signature = 'wa:reminders
        {--dry : Tampilkan target tanpa kirim}
        {--only= : Batasi ke satu username (untuk uji coba)}';
    protected $description = 'Kirim reminder H-1 + tagihan perpanjangan ke pelanggan yang Expiration di radcheck akan habis';

    public function __construct(
        private InvoiceService $invoices,
        private BillingNotificationService $notifier,
        private CustomerPriceService $prices,
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
            ->orderBy('customer_contacts.id')
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

        $dry      = (bool) $this->option('dry');
        $waNotif  = (bool) config('services.billing.wa_notifications', true);
        $testMode = (bool) config('services.billing.wa_test_mode', false);
        $testNumber = (string) config('services.billing.wa_test_number');

        $count       = 0;
        $withInvoice = 0;
        $tanpaHarga  = 0;
        $gagal       = 0;
        $sentLines   = [];

        foreach ($rows as $r) {
            try {
                $hasil = $this->prosesSatu($r, $dry, $waNotif, $testMode, $testNumber);
            } catch (\Throwable $e) {
                $gagal++;
                Log::error('wa.reminders.gagal', ['username' => $r->username, 'error' => $e->getMessage()]);
                $this->error("[GAGAL] {$r->username}: {$e->getMessage()}");
                continue;
            }

            $count++;
            $withInvoice += in_array($hasil['mode'], ['existing', 'new'], true) ? 1 : 0;
            $tanpaHarga  += $hasil['mode'] === 'tanpa_harga' ? 1 : 0;

            if ($hasil['baris'] !== null) {
                $sentLines[] = $hasil['baris'];
            }
        }

        if ($sentLines && ! $dry && $waNotif) {
            $this->notifyAdminSummary($sentLines);
        }

        $prefix    = $dry ? '[DRY] ' : '';
        $modeNote  = $testMode ? " (test mode → {$testNumber})" : '';
        $gagalNote = $gagal > 0 ? ", {$gagal} gagal" : '';
        $this->info("{$prefix}Total {$count} reminder{$modeNote}, {$withInvoice} dengan tagihan, {$tanpaHarga} tanpa harga{$gagalNote}.");

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function prosesSatu(object $r, bool $dry, bool $waNotif, bool $testMode, string $testNumber): array
    {
        $portal = (string) config('services.billing.portal_url');
        $expiry = $this->parseExpiry($r->expiry_raw);
        $expiryStr = $expiry
            ? $expiry->format('d M Y') . ' pukul ' . $expiry->format('H:i')
            : $r->expiry_raw;

        $sapaan = $r->name ?: $r->username;
        $plan   = $this->planInvoice($r->username, $expiry);
        $target = $testMode
            ? $testNumber
            : \App\Services\WhatsAppService::normalizePhone($r->phone);

        if ($dry) {
            $note    = $testMode ? " [→ test {$testNumber}]" : '';
            $tagihan = match ($plan['mode']) {
                'existing' => 'invoice #' . $plan['invoice']->id . ' (sudah ada) Rp ' . number_format($plan['invoice']->amount, 0, ',', '.'),
                'new'      => 'buat invoice baru Rp ' . number_format($plan['amount'], 0, ',', '.'),
                'gratis'   => 'TANPA invoice (akun gratis)',
                default    => 'TANPA invoice (belum ada harga)',
            };
            $this->line("[DRY] {$r->phone} ({$r->username}), habis {$r->expiry_raw}: {$tagihan}{$note}");

            return ['mode' => $plan['mode'], 'baris' => null];
        }

        if (! $waNotif) {
            $this->line('[SKIP] BILLING_WA_NOTIFICATIONS=false');

            return ['mode' => 'skip', 'baris' => null];
        }

        $invoice = $plan['mode'] === 'new'
            ? $this->invoices->createForUser($r->username, $plan['amount'], $plan['due_date'], $plan['profile'])
            : ($plan['invoice'] ?? null);

        $delay = now()->addSeconds(rand(2, 30));
        $baris = null;

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

            $baris = "• *{$r->username}* (habis {$expiry?->format('d M Y')}) • "
                . 'Rp ' . number_format($invoice->amount, 0, ',', '.') . " (inv #{$invoice->id})";
        } else {
            $msg = MessageTemplateService::render('reminder_polos', [
                'name'     => $sapaan,
                'username' => $r->username,
                'expiry'   => $expiryStr,
            ]);

            SendWhatsAppMessage::dispatch($target, $msg, $r->contact_id, null, true)->delay($delay);
        }

        CustomerContact::where('id', $r->contact_id)->update(['reminder_sent_at' => now()]);

        return ['mode' => $plan['mode'], 'baris' => $baris];
    }

    private function planInvoice(string $username, ?Carbon $expiry): array
    {
        if ($this->prices->isGratis($username)) {
            return ['mode' => 'gratis'];
        }

        $open = Invoice::where('username', $username)
            ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING])
            ->orderByDesc('id')
            ->first();

        if ($open) {
            return ['mode' => 'existing', 'invoice' => $open];
        }

        $harga = $this->prices->harga($username);

        if ($harga === null) {
            return ['mode' => 'tanpa_harga'];
        }

        return [
            'mode'     => 'new',
            'amount'   => $harga,
            'profile'  => RadUserGroup::where('username', $username)->orderBy('priority')->value('groupname') ?: InvoiceService::DEFAULT_PROFILE,
            'due_date' => ($expiry ? $expiry->copy() : now()->addDay())->endOfDay(),
        ];
    }

    private function notifyAdminSummary(array $sent): void
    {
        $this->notifier->notifyAdmin(
            MessageTemplateService::render('admin_ringkasan_reminder', [
                'total'          => count($sent),
                'dengan_tagihan' => count($sent),
                'tanpa_tagihan'  => 0,
                'daftar'         => implode("\n", $sent) . "\n",
                'blok_manual'    => '',
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
