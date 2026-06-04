<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Services\Products\LatestArrivalAutoMarkPolicy;
use App\Services\Products\ProductBulkUpdateService;

/**
 * Sets latest_arrival on PO products (ERP only until push). Skips tools by default.
 */
final class PurchaseOrderWorkflowMarkLatestArrivalService
{
    public function __construct(
        private readonly PurchaseOrderProductScopeService $scope,
        private readonly ProductBulkUpdateService $bulkUpdate,
        private readonly LatestArrivalAutoMarkPolicy $autoMarkPolicy,
    ) {}

    /**
     * @return array{updated: int, skipped_tools: int}
     */
    public function markForPo(string $purchaseOrderUuid): array
    {
        $toMark = [];
        $skippedTools = 0;

        foreach ($this->scope->productsForPo($purchaseOrderUuid, false) as $product) {
            if (! $this->autoMarkPolicy->shouldAutoMarkLatestArrival($product)) {
                $skippedTools++;

                continue;
            }
            $toMark[] = (string) $product->uuid;
        }

        if ($toMark === []) {
            return ['updated' => 0, 'skipped_tools' => $skippedTools];
        }

        $updated = $this->bulkUpdate->updateByUuids($toMark, [
            'latest_arrival' => true,
        ]);

        return ['updated' => $updated, 'skipped_tools' => $skippedTools];
    }
}
