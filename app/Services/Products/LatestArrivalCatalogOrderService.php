<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds storefront Latest Arrivals order.
 *
 * 1. Group products by received purchase orders only (newest received_date first).
 * 2. Unreceived POs (null received_date) and POs with exclude_from_latest_arrivals_ordering are ignored.
 * 3. Products on multiple POs are placed only under their newest received PO.
 * 4. Products with no received PO line fall back to grade-only sort at the end.
 * 5. Within each PO, sort by grade order (see config/latest_arrival.php), then newest created_at.
 */
final class LatestArrivalCatalogOrderService
{
    public function __construct(
        private readonly LatestArrivalPushProductSortService $productSort,
    ) {}

    /**
     * @return array<int, Product>
     */
    public function orderedLatestArrivalProducts(): array
    {
        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->where('latest_arrival', '=', true)
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        /** @var array<int, Product> $byId */
        $byId = [];
        foreach ($products as $product) {
            $byId[(int) $product->id] = $product;
        }

        $productIds = array_keys($byId);
        /** @var array<int, array{purchase_order_id: int, po_sort_date: string}> $bestPoByProduct */
        $bestPoByProduct = [];

        $rows = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereIn('poi.product_id', $productIds)
            ->whereNotNull('po.received_date')
            ->where('po.exclude_from_latest_arrivals_ordering', '=', false)
            ->select([
                'poi.product_id',
                'po.id as purchase_order_id',
                'po.received_date as po_sort_date',
            ])
            ->get();

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $poId = (int) $row->purchase_order_id;
            $date = (string) $row->po_sort_date;
            if (! isset($bestPoByProduct[$productId])) {
                $bestPoByProduct[$productId] = [
                    'purchase_order_id' => $poId,
                    'po_sort_date' => $date,
                ];

                continue;
            }

            $current = $bestPoByProduct[$productId];
            if ($this->isNewerPo($date, $poId, (string) $current['po_sort_date'], (int) $current['purchase_order_id'])) {
                $bestPoByProduct[$productId] = [
                    'purchase_order_id' => $poId,
                    'po_sort_date' => $date,
                ];
            }
        }

        /** @var array<int, array<int, Product>> $byPo */
        $byPo = [];
        /** @var array<int, Product> $withoutPo */
        $withoutPo = [];
        foreach ($byId as $productId => $product) {
            if (isset($bestPoByProduct[$productId])) {
                $poId = $bestPoByProduct[$productId]['purchase_order_id'];
                $byPo[$poId][] = $product;
            } else {
                $withoutPo[] = $product;
            }
        }

        /** @var array<int, array{purchase_order_id: int, po_sort_date: string}> $poOrderKeys */
        $poOrderKeys = [];
        foreach ($bestPoByProduct as $info) {
            $poId = (int) $info['purchase_order_id'];
            if (! isset($poOrderKeys[$poId])) {
                $poOrderKeys[$poId] = $info;
            }
        }
        uasort(
            $poOrderKeys,
            fn (array $a, array $b): int => $this->comparePoRecency(
                (string) $b['po_sort_date'],
                (int) $b['purchase_order_id'],
                (string) $a['po_sort_date'],
                (int) $a['purchase_order_id'],
            ),
        );

        $ordered = [];
        foreach (array_keys($poOrderKeys) as $poId) {
            if (! isset($byPo[$poId])) {
                continue;
            }
            foreach ($this->productSort->sortProducts($byPo[$poId]) as $product) {
                $ordered[] = $product;
            }
        }

        if ($withoutPo !== []) {
            foreach ($this->productSort->sortProducts($withoutPo) as $product) {
                $ordered[] = $product;
            }
        }

        return $ordered;
    }

    private function isNewerPo(string $date, int $poId, string $otherDate, int $otherPoId): bool
    {
        return $this->comparePoRecency($date, $poId, $otherDate, $otherPoId) > 0;
    }

    private function comparePoRecency(string $aDate, int $aPoId, string $bDate, int $bPoId): int
    {
        $cmp = strcmp($aDate, $bDate);
        if ($cmp !== 0) {
            return $cmp;
        }

        return $aPoId <=> $bPoId;
    }
}
