<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrder;
use App\Services\Products\LatestArrivalPushProductSortService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PurchaseOrderQueryService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderProductMetricsService $productMetrics,
        private readonly LatestArrivalPushProductSortService $productSort,
    ) {}

    /**
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $statuses
     */
    public function paginate(int $perPage, string $sortDir = 'desc', string $sortBy = 'ordered', array $vendors = [], array $statuses = []): LengthAwarePaginator
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

        $this->productMetrics->hydratePurchaseOrderItems($po->items);

        $po->setRelation('items', $this->productSort->sortPurchaseOrderItems($po->items));

        return $po;
    }
}
