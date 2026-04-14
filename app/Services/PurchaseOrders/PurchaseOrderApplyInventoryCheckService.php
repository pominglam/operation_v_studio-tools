<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Inventory\InventoryRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\InventoryCheckItem;
use App\Models\PurchaseOrderItem;
use App\Services\Products\InventoryCheckQueryService;
use App\Services\Products\ProductLatestCostCacheService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderApplyInventoryCheckService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly InventoryCheckQueryService $inventoryChecks,
        private readonly InventoryRepository $inventory,
        private readonly ProductLatestCostCacheService $latestCosts,
    ) {}

    /**
     * Resets receipt on this PO (removes PO-linked lots and their movements, clears qty_received),
     * then overwrites qty_received from inventory check lines matched by trimmed SKU.
     *
     * @return array{
     *   lines_updated: int,
     *   warnings: list<array<string, mixed>>,
     *   reset: array{movements_deleted: int, lots_deleted: int, qty_received_cleared: int}
     * }
     */
    public function apply(string $purchaseOrderUuid, string $inventoryCheckUuid): array
    {
        return DB::transaction(function () use ($purchaseOrderUuid, $inventoryCheckUuid): array {
            $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
            $po->loadMissing('items');

            $check = $this->inventoryChecks->findByUuidOrFail($inventoryCheckUuid);
            $check->loadMissing('items');

            $itemIds = $po->items->pluck('id')->map(static fn ($v): int => (int) $v)->all();
            $reset = $this->inventory->deleteMovementsAndLotsForPurchaseOrderItems($itemIds);
            $reset['qty_received_cleared'] = $this->clearQtyReceivedOnAllLines($po->items);

            $qtyBySku = $this->aggregateQuantitiesBySku($check->items);
            $poSkus = $this->nonEmptySkusOnPurchaseOrder($po->items);
            $warnings = $this->warningsForCheckSkusNotOnPurchaseOrder($qtyBySku, $poSkus);

            $linesUpdated = $this->overwriteQtyReceivedFromCheck($po->items, $qtyBySku, $warnings);

            $this->latestCosts->recomputeForSkus($po->items->pluck('sku')->all());

            return [
                'lines_updated' => $linesUpdated,
                'warnings' => $warnings,
                'reset' => [
                    'movements_deleted' => $reset['movements_deleted'],
                    'lots_deleted' => $reset['lots_deleted'],
                    'qty_received_cleared' => $reset['qty_received_cleared'],
                ],
            ];
        });
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $items
     */
    private function clearQtyReceivedOnAllLines(Collection $items): int
    {
        $n = 0;
        foreach ($items as $item) {
            $item->qty_received = null;
            $this->purchaseOrders->saveItem($item);
            $n++;
        }

        return $n;
    }

    /**
     * @param  array<string, int>  $qtyBySku
     * @param  array<string, true>  $poSkus
     * @return list<array<string, mixed>>
     */
    private function warningsForCheckSkusNotOnPurchaseOrder(array $qtyBySku, array $poSkus): array
    {
        $warnings = [];
        foreach (array_keys($qtyBySku) as $sku) {
            if (! isset($poSkus[$sku])) {
                $warnings[] = [
                    'kind' => 'check_sku_not_on_po',
                    'sku' => $sku,
                    'quantity_in_store' => $qtyBySku[$sku],
                ];
            }
        }

        return $warnings;
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $items
     * @param  array<string, int>  $qtyBySku
     * @param  list<array<string, mixed>>  $warnings
     */
    private function overwriteQtyReceivedFromCheck(
        Collection $items,
        array $qtyBySku,
        array &$warnings,
    ): int {
        $linesUpdated = 0;
        foreach ($items as $item) {
            $sku = $this->normalizeSku((string) $item->sku);
            if ($sku === '') {
                $warnings[] = [
                    'kind' => 'po_line_empty_sku',
                    'purchase_order_item_id' => (int) $item->id,
                ];

                continue;
            }

            if (! isset($qtyBySku[$sku])) {
                $warnings[] = [
                    'kind' => 'po_line_no_inventory_match',
                    'purchase_order_item_id' => (int) $item->id,
                    'sku' => $sku,
                ];

                continue;
            }

            $item->qty_received = $qtyBySku[$sku];
            $this->purchaseOrders->saveItem($item);
            $linesUpdated++;
        }

        return $linesUpdated;
    }

    /**
     * @param  Collection<int, InventoryCheckItem>  $items
     * @return array<string, int>
     */
    private function aggregateQuantitiesBySku(Collection $items): array
    {
        $out = [];
        foreach ($items as $line) {
            $sku = $this->normalizeSku((string) $line->sku);
            if ($sku === '') {
                continue;
            }
            $qty = max(0, (int) ($line->quantity_in_store ?? 0));
            $out[$sku] = ($out[$sku] ?? 0) + $qty;
        }

        return $out;
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $items
     * @return array<string, true>
     */
    private function nonEmptySkusOnPurchaseOrder(Collection $items): array
    {
        $set = [];
        foreach ($items as $item) {
            $sku = $this->normalizeSku((string) $item->sku);
            if ($sku !== '') {
                $set[$sku] = true;
            }
        }

        return $set;
    }

    private function normalizeSku(string $sku): string
    {
        return trim($sku);
    }
}
