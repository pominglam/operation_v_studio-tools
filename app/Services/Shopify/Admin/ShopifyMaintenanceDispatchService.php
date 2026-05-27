<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin;

use App\Jobs\Shopify\PullShopifyInventoryToProductsJob;
use App\Jobs\Shopify\RebuildProductDemandRollupsJob;
use App\Jobs\Shopify\ShopifyOrderHistoricalBackfillJob;
use App\Models\Shopify\ShopifySyncLog;
use App\Services\Shopify\Admin\Auth\ShopifyOrderAccessScopeGuard;

final class ShopifyMaintenanceDispatchService
{
    public function __construct(
        private readonly ShopifyMaintenanceRunLogger $logger,
        private readonly ShopifyOrderAccessScopeGuard $orderScopes,
    ) {}

    public function queueHistoricalBackfill(): ShopifySyncLog
    {
        $this->orderScopes->assertHistoricalBackfillAllowed();

        $log = $this->logger->queue(ShopifyOpsStatusService::SYNC_KEY_ORDERS_HISTORICAL);
        ShopifyOrderHistoricalBackfillJob::dispatch($log->id);

        return $log;
    }

    public function queueDemandRebuild(): ShopifySyncLog
    {
        $log = $this->logger->queue(ShopifyOpsStatusService::SYNC_KEY_DEMAND_REBUILD);
        RebuildProductDemandRollupsJob::dispatch($log->id);

        return $log;
    }

    public function queueInventoryPull(): ShopifySyncLog
    {
        $log = $this->logger->queue(ShopifyOpsStatusService::SYNC_KEY_INVENTORY_PULL);
        PullShopifyInventoryToProductsJob::dispatch($log->id);

        return $log;
    }
}
