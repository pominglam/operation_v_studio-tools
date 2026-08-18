<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Inventory\InventoryRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Services\Products\ProductLatestCostCacheService;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderDeleteException;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderDeleteService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly InventoryRepository $inventory,
        private readonly ProductLatestCostCacheService $latestCosts,
    ) {}

    public function delete(string $uuid): void
    {
        DB::transaction(function () use ($uuid): void {
            $po = $this->purchaseOrders->findByUuidOrFail($uuid);

            if (! $po->relationLoaded('items')) {
                $po->load('items');
            }

            if ($this->purchaseOrders->hasCombinedPayment((int) $po->id)) {
                throw new PurchaseOrderDeleteException(
                    'Cannot delete a purchase order linked to a combined payment.',
                );
            }

            $skus = $po->items->pluck('sku')->all();

            $itemIds = $po->items->pluck('id')->all();
            if ($itemIds === []) {
                $this->purchaseOrders->delete($po);

                return;
            }

            $hasReceived = $po->items->contains(static fn ($it): bool => ((int) ($it->qty_received ?? 0)) > 0);
            $lots = $this->inventory->countLotsForPurchaseOrderItems($itemIds);

            if ($hasReceived || $lots > 0) {
                throw new PurchaseOrderDeleteException('Cannot delete a purchase order that has received inventory/lots. This would corrupt inventory history.');
            }

            $this->purchaseOrders->deleteItemsForPurchaseOrder((int) $po->id);
            $this->purchaseOrders->delete($po);

            $this->latestCosts->recomputeForSkus($skus);
        });
    }
}
