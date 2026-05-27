<?php

declare(strict_types=1);

namespace App\Jobs\Shopify;

use App\Models\Shopify\ShopifySyncLog;
use App\Services\Shopify\Admin\Inventory\ShopifyInventoryToProductsService;
use App\Services\Shopify\Admin\ShopifyMaintenanceRunLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class PullShopifyInventoryToProductsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public int $syncLogId,
    ) {}

    public function handle(
        ShopifyInventoryToProductsService $service,
        ShopifyMaintenanceRunLogger $logger,
    ): void {
        /** @var ShopifySyncLog $log */
        $log = ShopifySyncLog::query()->findOrFail($this->syncLogId);
        $logger->markRunning($log);

        try {
            $result = $service->pullToAvailableQty();
            $logger->complete($log, $result);
        } catch (Throwable $e) {
            $logger->fail($log, $e->getMessage());
            throw $e;
        }
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
