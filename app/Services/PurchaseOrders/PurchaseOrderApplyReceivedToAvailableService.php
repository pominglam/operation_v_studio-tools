<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderApplyReceivedToAvailableService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderWorkflowPrepareInventoryService $prepareInventory,
    ) {}

    /**
     * @return array{
     *   products_updated:int,
     *   total_added:int,
     *   total_damaged:int,
     *   lines_considered:int,
     *   skipped_missing_product_id:int,
     *   skipped_non_positive_qty:int
     * }
     */
    public function apply(string $purchaseOrderUuid): array
    {
        $this->prepareInventory->validateReceivedQuantities($purchaseOrderUuid);

        return DB::transaction(function () use ($purchaseOrderUuid): array {
            $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
            $items = $this->purchaseOrders->itemsForPurchaseOrderId((int) $po->id);

            $byProductId = [];
            $skippedMissingProductId = 0;
            $skippedNonPositiveQty = 0;
            $totalDamaged = 0;

            foreach ($items as $item) {
                $qtyReceived = (int) ($item->qty_received ?? 0);
                $qtyDamaged = (int) ($item->qty_damaged ?? 0);
                $qtyToAdd = max(0, $qtyReceived - $qtyDamaged);
                $productId = (int) ($item->product_id ?? 0);
                $totalDamaged += $qtyDamaged;
                if ($productId <= 0) {
                    $skippedMissingProductId++;

                    continue;
                }
                if ($qtyToAdd <= 0) {
                    $skippedNonPositiveQty++;

                    continue;
                }

                $byProductId[$productId] = (int) ($byProductId[$productId] ?? 0) + $qtyToAdd;
            }

            $productsUpdated = 0;
            $totalAdded = 0;
            foreach ($byProductId as $productId => $qtyToAdd) {
                /** @var Product|null $product */
                $product = Product::query()->find((int) $productId);
                if (! $product) {
                    continue;
                }

                $current = (int) ($product->available_qty ?? 0);
                $product->available_qty = $current + (int) $qtyToAdd;
                $product->save();

                $productsUpdated++;
                $totalAdded += (int) $qtyToAdd;
            }

            return [
                'products_updated' => $productsUpdated,
                'total_added' => $totalAdded,
                'total_damaged' => $totalDamaged,
                'lines_considered' => count($items),
                'skipped_missing_product_id' => $skippedMissingProductId,
                'skipped_non_positive_qty' => $skippedNonPositiveQty,
            ];
        });
    }
}
