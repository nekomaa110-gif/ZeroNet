<?php

namespace App\Jobs;

use App\Services\VoucherWaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class SendVoucherWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(public int $deliveryId) {}

    public function handle(VoucherWaService $service): void
    {
        $service->kirim($this->deliveryId);
    }

    public function failed(?Throwable $e): void
    {
        app(VoucherWaService::class)->tandaiBelumPasti($this->deliveryId, 'Proses kirim terhenti di tengah jalan');
    }
}
