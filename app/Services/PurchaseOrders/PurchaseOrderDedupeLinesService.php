<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\InventoryLot;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Products\ProductLatestCostCacheService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderDedupeLinesService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderLineMergeService $lineMerge,
        private readonly PurchaseOrderDerivedTotalsService $derivedTotals,
        private readonly ProductLatestCostCacheService $latestCosts,
    ) {}

    /**
     * @return list<array{
     *   purchase_order_uuid:string,
     *   sku:string,
     *   survivor_item_id:int,
     *   removed_item_ids:array<int, int>,
     *   cost_mismatch:bool,
     *   merged_qty_received:?int
     * }>
     */
    public function dedupeAll(bool $dryRun = true): array
    {
        /** @var Collection<int, PurchaseOrder> $purchaseOrders */
        $purchaseOrders = PurchaseOrder::query()->orderBy('id')->get();
        $report = [];

        foreach ($purchaseOrders as $po) {
            $report = array_merge($report, $this->dedupePurchaseOrder($po, $dryRun));
        }

        return $report;
    }

    /**
     * @return list<array{
     *   purchase_order_uuid:string,
     *   sku:string,
     *   survivor_item_id:int,
     *   removed_item_ids:array<int, int>,
     *   cost_mismatch:bool,
     *   merged_qty_received:?int
     * }>
     */
    public function dedupeByUuid(string $purchaseOrderUuid, bool $dryRun = true): array
    {
        $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);

        return $this->dedupePurchaseOrder($po, $dryRun);
    }

    /**
     * @return list<array{
     *   purchase_order_uuid:string,
     *   sku:string,
     *   survivor_item_id:int,
     *   removed_item_ids:array<int, int>,
     *   cost_mismatch:bool,
     *   merged_qty_received:?int
     * }>
     */
    private function dedupePurchaseOrder(PurchaseOrder $po, bool $dryRun): array
    {
        return DB::transaction(function () use ($po, $dryRun): array {
            $po->loadMissing('items');
            /** @var array<string, list<PurchaseOrderItem>> $groups */
            $groups = [];

            foreach ($po->items as $item) {
                $groups[$this->lineMerge->groupKeyForItem($item)][] = $item;
            }

            $report = [];
            $touchedSkus = [];

            foreach ($groups as $group) {
                if (count($group) < 2) {
                    continue;
                }

                usort($group, static fn (PurchaseOrderItem $a, PurchaseOrderItem $b): int => (int) $a->id <=> (int) $b->id);
                /** @var PurchaseOrderItem $survivor */
                $survivor = $group[0];
                /** @var list<PurchaseOrderItem> $duplicates */
                $duplicates = array_slice($group, 1);
                $costMismatch = $this->hasDistinctUnitCosts([$survivor, ...$duplicates]);
                $removedIds = array_map(static fn (PurchaseOrderItem $i): int => (int) $i->id, $duplicates);

                $report[] = [
                    'purchase_order_uuid' => (string) $po->uuid,
                    'sku' => (string) $survivor->sku,
                    'survivor_item_id' => (int) $survivor->id,
                    'removed_item_ids' => $removedIds,
                    'cost_mismatch' => $costMismatch,
                    'merged_qty_received' => $this->lineMerge->mergeQtyReceivedForDedup(
                        ...array_map(
                            static fn (PurchaseOrderItem $i): ?int => $i->qty_received !== null ? (int) $i->qty_received : null,
                            [$survivor, ...$duplicates],
                        ),
                    ),
                ];

                if ($dryRun) {
                    continue;
                }

                $this->lineMerge->mergeDuplicateItemsOntoSurvivor($survivor, $duplicates);
                $this->purchaseOrders->saveItem($survivor);

                if ($removedIds !== []) {
                    InventoryLot::query()
                        ->whereIn('purchase_order_item_id', $removedIds)
                        ->update([
                            'purchase_order_item_id' => (int) $survivor->id,
                            'updated_at' => now(),
                        ]);

                    PurchaseOrderItem::query()->whereIn('id', $removedIds)->delete();
                }

                $touchedSkus[] = (string) $survivor->sku;
            }

            if (! $dryRun && $report !== []) {
                $this->derivedTotals->recompute($po);
                if ($touchedSkus !== []) {
                    $this->latestCosts->recomputeForSkus(array_values(array_unique($touchedSkus)));
                }
            }

            return $report;
        });
    }

    /**
     * @param  list<PurchaseOrderItem>  $items
     */
    private function hasDistinctUnitCosts(array $items): bool
    {
        $costs = [];
        foreach ($items as $item) {
            if ($item->unit_cost === null) {
                continue;
            }
            $costs[(string) $item->unit_cost] = true;
        }

        return count($costs) > 1;
    }
}
