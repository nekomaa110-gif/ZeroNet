<?php

namespace App\Jobs;

use App\Exceptions\RouterSibuk;
use App\Models\VoucherBatch;
use App\Services\ActivityLogService;
use App\Services\VoucherPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncVoucherBatch implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [30, 120, 300];
    public int $timeout = 600;

    public int $uniqueFor = 1200;

    public function __construct(public int $batchId) {}

    public function uniqueId(): string
    {
        return 'voucher-batch-' . $this->batchId;
    }

    public function handle(VoucherPushService $push): void
    {
        $batch = VoucherBatch::find($this->batchId);

        if (! $batch) {
            return;
        }

        try {
            $hasil = $push->push($batch);
        } catch (RouterSibuk) {
            $this->release(30);

            return;
        }

        ActivityLogService::log(
            action: 'voucher_sync',
            description: "Sinkron batch voucher {$batch->code} ke {$batch->router_label}: "
                . "{$hasil['ok']} berhasil, {$hasil['failed']} gagal",
            subjectType: 'voucher_batch',
            subjectId: (string) $batch->id,
            properties: $hasil,
            userId: $batch->created_by,
        );

        if ($hasil['failed'] > 0 && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }

    public function failed(?Throwable $e): void
    {
        $batch = VoucherBatch::find($this->batchId);

        if (! $batch) {
            return;
        }

        $batch->update([
            'status' => $batch->synced_count > 0 ? 'partial' : 'failed',
            'error'  => mb_substr($e?->getMessage() ?? 'Sinkronisasi gagal.', 0, 500),
        ]);

        ActivityLogService::log(
            action: 'voucher_sync_failed',
            description: "Batch voucher {$batch->code} gagal disinkronkan ke {$batch->router_label}",
            subjectType: 'voucher_batch',
            subjectId: (string) $batch->id,
            properties: ['error' => $e?->getMessage()],
            userId: $batch->created_by,
        );
    }
}
