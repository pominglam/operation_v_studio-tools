<?php

declare(strict_types=1);

namespace App\Support\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

final class ProductLatestArrivedLandedUnitCostResolver
{
    public function __construct(
        private readonly PurchaseOrderLandedUnitCostResolver $landedUnitCosts,
    ) {}

    /**
     * Landed unit cost from the latest PO with an entered shipping total.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string> product_id => landed money2
     */
    public function landedByProductId(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map(intval(...), $productIds))));
        if ($productIds === []) {
            return [];
        }

        /** @var array<int, int> $latestItemIdByProductId */
        $latestItemIdByProductId = [];

        $rows = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereIn('poi.product_id', $productIds)
            ->whereNotNull('po.shipping_total')
            ->orderByDesc('po.ordered_date')
            ->orderByDesc('po.id')
            ->orderByDesc('poi.id')
            ->get(['poi.id as item_id', 'poi.product_id']);

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            if ($productId <= 0 || isset($latestItemIdByProductId[$productId])) {
                continue;
            }

            $latestItemIdByProductId[$productId] = (int) $row->item_id;
        }

        if ($latestItemIdByProductId === []) {
            return [];
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, PurchaseOrderItem> $items */
        $items = PurchaseOrderItem::query()
            ->whereIn('id', array_values($latestItemIdByProductId))
            ->get()
            ->keyBy('id');

        $purchaseOrderIds = $items->pluck('purchase_order_id')->unique()->values()->all();
        /** @var \Illuminate\Database\Eloquent\Collection<int, PurchaseOrder> $purchaseOrders */
        $purchaseOrders = PurchaseOrder::query()
            ->whereIn('id', $purchaseOrderIds)
            ->get()
            ->keyBy('id');

        /** @var \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Collection<int, PurchaseOrderItem>> $allItemsByPoId */
        $allItemsByPoId = PurchaseOrderItem::query()
            ->whereIn('purchase_order_id', $purchaseOrderIds)
            ->get()
            ->groupBy('purchase_order_id');

        /** @var array<int, array<int, string>> $landedByPoId */
        $landedByPoId = [];
        $out = [];
        foreach ($latestItemIdByProductId as $productId => $itemId) {
            /** @var PurchaseOrderItem|null $item */
            $item = $items->get($itemId);
            if ($item === null) {
                continue;
            }

            /** @var PurchaseOrder|null $sourcePo */
            $sourcePo = $purchaseOrders->get((int) $item->purchase_order_id);
            if ($sourcePo === null) {
                continue;
            }

            $sourcePoId = (int) $sourcePo->id;
            if (! isset($landedByPoId[$sourcePoId])) {
                $landedByPoId[$sourcePoId] = $this->landedUnitCosts->landedByProductId(
                    $sourcePo,
                    $allItemsByPoId->get($sourcePoId, collect()),
                );
            }

            if (isset($landedByPoId[$sourcePoId][$productId])) {
                $out[$productId] = $landedByPoId[$sourcePoId][$productId];
            }
        }

        return $out;
    }

    /**
     * @return array{latest_unit_cost: string|null, latest_landed_unit_cost: string|null}
     */
    public function latestCostsForSku(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return ['latest_unit_cost' => null, 'latest_landed_unit_cost' => null];
        }

        /** @var object{item_id: int, product_id: int}|null $row */
        $row = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('poi.sku', '=', $sku)
            ->whereNotNull('po.shipping_total')
            ->orderByDesc('po.ordered_date')
            ->orderByDesc('po.id')
            ->orderByDesc('poi.id')
            ->select(['poi.id as item_id', 'poi.product_id'])
            ->first();

        if ($row === null) {
            return ['latest_unit_cost' => null, 'latest_landed_unit_cost' => null];
        }

        $productId = (int) $row->product_id;
        if ($productId <= 0) {
            return ['latest_unit_cost' => null, 'latest_landed_unit_cost' => null];
        }

        $landedByProductId = $this->landedByProductId([$productId]);
        $landed = $landedByProductId[$productId] ?? null;
        if ($landed === null) {
            return ['latest_unit_cost' => null, 'latest_landed_unit_cost' => null];
        }

        /** @var PurchaseOrderItem|null $item */
        $item = PurchaseOrderItem::query()->find((int) $row->item_id);
        if ($item === null) {
            return ['latest_unit_cost' => null, 'latest_landed_unit_cost' => $landed];
        }

        /** @var PurchaseOrder|null $sourcePo */
        $sourcePo = PurchaseOrder::query()->find((int) $item->purchase_order_id);
        $unit = $sourcePo !== null
            ? app(PurchaseOrderItemCadUnitCostResolver::class)->resolve($item, $sourcePo)
            : null;

        return [
            'latest_unit_cost' => $unit,
            'latest_landed_unit_cost' => $landed,
        ];
    }
}
