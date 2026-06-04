<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

/**
 * @deprecated Prefer separate {@see PurchaseOrderWorkflowMarkPublishedOnShopifyService}
 *             and {@see PurchaseOrderWorkflowMarkLatestArrivalService}.
 */
final class PurchaseOrderWorkflowMarkLatestArrivalPublishedService
{
    public function __construct(
        private readonly PurchaseOrderWorkflowMarkPublishedOnShopifyService $markPublished,
        private readonly PurchaseOrderWorkflowMarkLatestArrivalService $markLatestArrival,
    ) {}

    /**
     * @return array{updated: int, skipped_tools: int}
     */
    public function markForPo(string $purchaseOrderUuid): array
    {
        $published = $this->markPublished->markForPo($purchaseOrderUuid);
        $latest = $this->markLatestArrival->markForPo($purchaseOrderUuid);

        return [
            'updated' => max($published['updated'], $latest['updated']),
            'skipped_tools' => $latest['skipped_tools'],
        ];
    }
}
