<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Services\Products\ProductBulkUpdateService;

/**
 * Sets published_on_shopify on all PO products (ERP only until push).
 */
final class PurchaseOrderWorkflowMarkPublishedOnShopifyService
{
    public function __construct(
        private readonly PurchaseOrderProductScopeService $scope,
        private readonly ProductBulkUpdateService $bulkUpdate,
    ) {}

    /**
     * @return array{updated: int}
     */
    public function markForPo(string $purchaseOrderUuid): array
    {
        $uuids = $this->scope->productUuidsForPo($purchaseOrderUuid, false);
        if ($uuids === []) {
            return ['updated' => 0];
        }

        $updated = $this->bulkUpdate->updateByUuids($uuids, [
            'published_on_shopify' => true,
        ]);

        return ['updated' => $updated];
    }
}
