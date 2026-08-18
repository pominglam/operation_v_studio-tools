<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Services\Products\ProductInboundOpenPoQtySql;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderProductMetricsService
{
    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{
     *   available:int|null,
     *   maintain:int|null,
     *   not_arrived:int,
     *   reorder:int,
     *   total_ordered:int,
     *   total_sold:int,
     *   latest_landed_unit_cost:string|null,
     *   selling_price:string|null,
     *   multiplier:string|null
     * }>
     */
    public function metricsByProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $productIds,
        ), static fn (int $id): bool => $id > 0)));

        if ($productIds === []) {
            return [];
        }

        $inboundOpenPoQtyExpr = ProductInboundOpenPoQtySql::expression(false, 'p.id', 'p.sku');

        /** @var array<int, object{
         *   id:int,
         *   available_qty:int|null,
         *   maintain_qty:int|null,
         *   latest_landed_unit_cost:string|null,
         *   selling_price:string|null,
         *   not_arrived:int|string|null,
         *   total_ordered:int|string|null
         * }> $rows
         */
        $rows = DB::table('products as p')
            ->leftJoin('product_selling_prices as sps', 'sps.product_id', '=', 'p.id')
            ->whereIn('p.id', $productIds)
            ->select([
                'p.id',
                'p.available_qty',
                'p.maintain_qty',
                'p.latest_landed_unit_cost',
                DB::raw('sps.selling_price as selling_price'),
                DB::raw("{$inboundOpenPoQtyExpr} as not_arrived"),
                DB::raw('(
                    select coalesce(sum(coalesce(poi.qty_received, 0)), 0)
                    from purchase_order_items poi
                    inner join purchase_orders po on po.id = poi.purchase_order_id
                    where poi.product_id = p.id
                      and po.received_date is not null
                ) as total_ordered'),
            ])
            ->get()
            ->all();

        $out = [];
        foreach ($rows as $row) {
            $available = $row->available_qty !== null ? (int) $row->available_qty : null;
            $maintain = $row->maintain_qty !== null ? (int) $row->maintain_qty : null;
            $notArrived = max(0, (int) ($row->not_arrived ?? 0));
            $totalOrdered = max(0, (int) ($row->total_ordered ?? 0));
            $reorderBase = ($maintain ?? 0) - ($available ?? 0) - $notArrived;
            $reorder = max(0, $reorderBase);
            $totalSold = $totalOrdered - ($available ?? 0);

            $latestLanded = $this->money2($row->latest_landed_unit_cost);
            $selling = $this->money2($row->selling_price);
            $multiplier = null;
            if ($latestLanded !== null && $selling !== null) {
                $cost = (float) $latestLanded;
                $price = (float) $selling;
                if ($cost > 0) {
                    $multiplier = number_format($price / $cost, 2, '.', '');
                }
            }

            $out[(int) $row->id] = [
                'available' => $available,
                'maintain' => $maintain,
                'not_arrived' => $notArrived,
                'reorder' => $reorder,
                'total_ordered' => $totalOrdered,
                'total_sold' => $totalSold,
                'latest_landed_unit_cost' => $latestLanded,
                'selling_price' => $selling,
                'multiplier' => $multiplier,
            ];
        }

        return $out;
    }

    /**
     * @param  EloquentCollection<int, PurchaseOrderItem>|iterable<int, PurchaseOrderItem>  $items
     */
    public function hydratePurchaseOrderItems(EloquentCollection|iterable $items): void
    {
        /** @var array<int, int> $productIdBySku */
        $productIdBySku = [];
        $productIds = [];

        foreach ($items as $item) {
            if (! $item instanceof PurchaseOrderItem) {
                continue;
            }

            if ($item->product_id !== null) {
                $productIds[] = (int) $item->product_id;

                continue;
            }

            $sku = trim($item->sku);
            if ($sku !== '') {
                $productIdBySku[$sku] = 0;
            }
        }

        if ($productIdBySku !== []) {
            $resolved = Product::query()
                ->whereIn('sku', array_keys($productIdBySku))
                ->pluck('id', 'sku')
                ->all();
            foreach ($resolved as $sku => $id) {
                $productIdBySku[(string) $sku] = (int) $id;
                $productIds[] = (int) $id;
            }
        }

        $metricsByProductId = $this->metricsByProductIds($productIds);

        foreach ($items as $item) {
            if (! $item instanceof PurchaseOrderItem) {
                continue;
            }

            $productId = $item->product_id !== null
                ? (int) $item->product_id
                : ($productIdBySku[trim($item->sku)] ?? 0);

            if ($productId <= 0 || ! isset($metricsByProductId[$productId])) {
                continue;
            }

            $this->applyMetricsToItem($item, $metricsByProductId[$productId]);
        }
    }

    /**
     * @param  array{
     *   available:int|null,
     *   maintain:int|null,
     *   not_arrived:int,
     *   reorder:int,
     *   total_ordered:int,
     *   total_sold:int,
     *   latest_landed_unit_cost:string|null,
     *   selling_price:string|null,
     *   multiplier:string|null
     * }  $metrics
     */
    private function applyMetricsToItem(PurchaseOrderItem $item, array $metrics): void
    {
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

    private function money2(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9\.\-]/', '', $trimmed) ?? '';
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }
}
