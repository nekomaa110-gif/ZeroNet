<?php

namespace App\Jobs;

use App\Models\WaBroadcastRecipient;
use App\Services\ActivityLogService;
use App\Services\WaSendTracker;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 60;

    public function __construct(
        public string $number,
        public string $message,
        public ?int $contactId = null,
        public ?int $userId = null,
        public bool $updateReminderSent = false,
        public ?string $trackId = null,
        public ?int $recipientId = null,
    ) {}

    private function track(string $status, array $extra = []): void
    {
        if ($this->trackId) {
            WaSendTracker::mark($this->trackId, $status, $extra);
        }
    }

    private function recipient(string $status, array $extra = []): void
    {
        if (! isset($this->recipientId)) {
            return;
        }

        $row = WaBroadcastRecipient::find($this->recipientId);
        if (! $row || $row->status === 'cancelled') {
            return;
        }

        $row->forceFill($extra + [
            'status'  => $status,
            'attempt' => $this->attempts(),
        ])->save();

        if (in_array($status, ['sent', 'failed'], true)) {
            $row->broadcast?->closeIfDone();
        }
    }

    private function dibatalkan(): bool
    {
        if (! isset($this->recipientId)) {
            return false;
        }

        $row = WaBroadcastRecipient::with('broadcast')->find($this->recipientId);

        return ! $row
            || $row->status === 'cancelled'
            || ($row->broadcast?->status === 'cancelled');
    }

    public function handle(WhatsAppService $wa): void
    {
        if ($this->dibatalkan()) {
            return;
        }

        $this->track('processing', ['attempt' => $this->attempts()]);
        $this->track('sending');
        $this->recipient('sending');

        $r = $wa->send($this->number, $this->message);

        if (!($r['ok'] ?? false)) {
            $err = $r['body']['error'] ?? null;
            if ($err === 'number_not_on_whatsapp') {
                $this->track('failed', ['error' => 'Nomor ini tidak terdaftar di WhatsApp.']);
                $this->recipient('failed', ['error' => 'Nomor tidak terdaftar di WhatsApp']);

                ActivityLogService::log(
                    action: 'wa_send',
                    description: "WA tidak terkirim ke {$this->number}: nomor bukan WhatsApp",
                    subjectType: 'wa_message',
                    subjectId: $this->contactId ? (string) $this->contactId : null,
                    properties: ['status' => 'not_on_whatsapp', 'number' => $this->number],
                    userId: $this->userId,
                );
                return;
            }
            ActivityLogService::log(
                action: 'wa_send',
                description: "WA gagal kirim ke {$this->number}",
                subjectType: 'wa_message',
                subjectId: $this->contactId ? (string) $this->contactId : null,
                properties: ['status' => 'failed', 'number' => $this->number, 'error' => $err],
                userId: $this->userId,
            );

            $pesanErr = $err === 'wa_not_ready'
                ? 'Gateway belum siap, mencoba lagi…'
                : ($err ?: 'Gagal kirim, mencoba lagi…');

            $this->track('processing', [
                'attempt' => $this->attempts(),
                'error'   => $pesanErr,
            ]);
            $this->recipient('sending', ['error' => $pesanErr]);

            throw new \RuntimeException('WA send failed: '.json_encode($r));
        }

        $this->track('sent', [
            'message_id' => $r['body']['id'] ?? null,
            'error'      => null,
        ]);
        $this->recipient('sent', ['error' => null, 'sent_at' => now()]);

        if ($this->contactId && $this->updateReminderSent) {
            \App\Models\CustomerContact::where('id', $this->contactId)
                ->update(['reminder_sent_at' => now()]);
        }

        ActivityLogService::log(
            action: 'wa_send',
            description: "WA terkirim ke {$this->number}",
            subjectType: 'wa_message',
            subjectId: $this->contactId ? (string) $this->contactId : null,
            properties: [
                'status'     => 'sent',
                'number'     => $this->number,
                'message_id' => $r['body']['id'] ?? null,
            ],
            userId: $this->userId,
        );
    }

    public function failed(\Throwable $e): void
    {
        $alasan = str_contains($e->getMessage(), 'wa_not_ready')
            ? 'Gateway WhatsApp tidak siap. Cek Panel Gateway.'
            : 'Gagal terkirim setelah '.$this->tries.' percobaan.';

        $this->track('failed', ['error' => $alasan]);
        $this->recipient('failed', ['error' => $alasan]);

        if ($this->contactId && $this->updateReminderSent) {
            \App\Models\CustomerContact::where('id', $this->contactId)
                ->update(['reminder_sent_at' => null]);
        }
    }
}
