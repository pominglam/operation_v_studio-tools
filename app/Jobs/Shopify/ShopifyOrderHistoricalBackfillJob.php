<?php

declare(strict_types=1);

namespace App\Jobs\Shopify;

use App\Models\Shopify\ShopifySyncLog;
use App\Services\Shopify\Admin\Orders\ShopifyOrderReconcileService;
use App\Services\Shopify\Admin\ShopifyMaintenanceRunLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ShopifyOrderHistoricalBackfillJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public int $syncLogId,
    ) {}

    public function handle(
        ShopifyOrderReconcileService $reconcile,
        ShopifyMaintenanceRunLogger $logger,
    ): void {
        /** @var ShopifySyncLog $log */
        $log = ShopifySyncLog::query()->findOrFail($this->syncLogId);
        $logger->markRunning($log);
        $reconcile->reconcileHistorical($log);
    }

    public function failed(Throwable $e): void
    {
        $log = ShopifySyncLog::query()->find($this->syncLogId);
        if ($log === null) {
            return;
        }

        app(ShopifyMaintenanceRunLogger::class)->fail($log, $e->getMessage());
    }
}
