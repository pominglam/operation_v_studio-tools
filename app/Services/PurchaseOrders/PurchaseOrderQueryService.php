<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PurchaseOrderQueryService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderProductMetricsService $productMetrics,
    ) {}

    /**
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $statuses
     */
    public function paginate(int $perPage, string $sortDir = 'desc', string $sortBy = 'created', array $vendors = [], array $statuses = []): LengthAwarePaginator
    {
        return $this->purchaseOrders->paginate($perPage, $sortDir, $sortBy, $vendors, $statuses);
    }

    /**
     * @return array<int, string>
     */
    public function distinctVendors(): array
    {
        return $this->purchaseOrders->distinctVendors();
    }

    public function findByUuidOrFail(string $uuid): PurchaseOrder
    {
        $po = $this->purchaseOrders->findByUuidOrFail($uuid);
        $po->loadMissing('items.product');

        $productIds = [];
        foreach ($po->items as $item) {
            if ($item->product_id !== null) {
                $productIds[] = (int) $item->product_id;
            }
        }
        $metricsByProductId = $this->productMetrics->metricsByProductIds($productIds);

        foreach ($po->items as $item) {
            $productId = $item->product_id !== null ? (int) $item->product_id : null;
            if ($productId === null || ! isset($metricsByProductId[$productId])) {
                continue;
            }
            $metrics = $metricsByProductId[$productId];
            $item->setAttribute('product_available', $metrics['available']);
            $item->setAttribute('product_maintain', $metrics['maintain']);
            $item->setAttribute('product_not_arrived', $metrics['not_arrived']);
            $item->setAttribute('product_reorder', $metrics['reorder']);
            $item->setAttribute('product_total_ordered', $metrics['total_ordered']);
            $item->setAttribute('product_total_sold', $metrics['total_sold']);
            $item->setAttribute('product_latest_landed_unit_cost', $metrics['latest_landed_unit_cost']);
            $item->setAttribute('product_selling_price', $metrics['selling_price']);
            $item->setAttribute('product_multiplier', $metrics['multiplier']);
        }

        return $po;
    }
}
