<?php

namespace App\Jobs;

use App\Services\ActivityLogService;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 90;

    public function __construct(
        public string $number,
        public string $url,
        public ?string $caption = null,
        public ?int $contactId = null,
        public ?int $userId = null,
        public array $viewport = [],
        public ?string $fallbackText = null,
        public bool $updateReminderSent = false,
    ) {}

    public function handle(WhatsAppService $wa): void
    {
        $r = $wa->sendImageUrl($this->number, $this->url, $this->caption, $this->viewport);

        if (!($r['ok'] ?? false)) {
            $err = $r['body']['error'] ?? null;
            if ($err === 'number_not_on_whatsapp') {
                ActivityLogService::log(
                    action: 'wa_send',
                    description: "WA image tidak terkirim ke {$this->number}: nomor bukan WhatsApp",
                    subjectType: 'wa_message',
                    subjectId: $this->contactId ? (string) $this->contactId : null,
                    properties: ['type' => 'image', 'status' => 'not_on_whatsapp', 'number' => $this->number, 'url' => $this->url],
                    userId: $this->userId,
                );
                return;
            }
            ActivityLogService::log(
                action: 'wa_send',
                description: "WA image gagal kirim ke {$this->number}",
                subjectType: 'wa_message',
                subjectId: $this->contactId ? (string) $this->contactId : null,
                properties: ['type' => 'image', 'status' => 'failed', 'number' => $this->number, 'url' => $this->url, 'error' => $err],
                userId: $this->userId,
            );
            throw new \RuntimeException('WA image send failed: '.json_encode($r));
        }

        if ($this->contactId && $this->updateReminderSent) {
            \App\Models\CustomerContact::where('id', $this->contactId)
                ->update(['reminder_sent_at' => now()]);
        }

        ActivityLogService::log(
            action: 'wa_send',
            description: "WA image terkirim ke {$this->number}",
            subjectType: 'wa_message',
            subjectId: $this->contactId ? (string) $this->contactId : null,
            properties: [
                'type'       => 'image',
                'status'     => 'sent',
                'number'     => $this->number,
                'url'        => $this->url,
                'message_id' => $r['body']['id'] ?? null,
                'png_bytes'  => $r['body']['png_bytes'] ?? null,
            ],
            userId: $this->userId,
        );
    }

    public function failed(\Throwable $e): void
    {
        \Log::warning('wa.image.failed_final', [
            'to' => $this->number, 'url' => $this->url, 'err' => $e->getMessage(),
        ]);

        if (! $this->fallbackText) {
            if ($this->contactId && $this->updateReminderSent) {
                \App\Models\CustomerContact::where('id', $this->contactId)
                    ->update(['reminder_sent_at' => null]);
            }

            return;
        }

        SendWhatsAppMessage::dispatch($this->number, $this->fallbackText, $this->contactId, $this->userId, $this->updateReminderSent);

        ActivityLogService::log(
            action: 'wa_send',
            description: "WA image gagal, fallback teks diantrikan ke {$this->number}",
            subjectType: 'wa_message',
            subjectId: $this->contactId ? (string) $this->contactId : null,
            properties: ['type' => 'image', 'status' => 'fallback_text', 'number' => $this->number, 'url' => $this->url],
            userId: $this->userId,
        );
    }
}
