<?php

namespace App\Jobs;

use App\Models\Router;
use App\Services\ActivityLogService;
use App\Services\VoucherImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ImportVoucherMikhmon implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public int $routerId, public ?int $userId = null) {}

    public function uniqueId(): string
    {
        return 'voucher-import-' . $this->routerId;
    }

    public function handle(VoucherImportService $import): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $hasil = $import->impor($router, $this->userId);

        ActivityLogService::log(
            action: 'voucher_import',
            description: "Impor voucher dari {$router->name}: {$hasil['masuk']} voucher baru dalam {$hasil['batch']} batch",
            subjectType: 'router',
            subjectId: $router->slug,
            properties: $hasil,
            userId: $this->userId,
        );
    }

    public function failed(?Throwable $e): void
    {
        ActivityLogService::log(
            action: 'voucher_import_failed',
            description: 'Impor voucher gagal: ' . mb_substr((string) $e?->getMessage(), 0, 200),
            subjectType: 'router',
            subjectId: (string) $this->routerId,
            userId: $this->userId,
        );
    }
}
