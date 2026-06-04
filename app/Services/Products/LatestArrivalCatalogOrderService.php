<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds storefront Latest Arrivals order: POs newest-first, then product sort within each PO.
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
            ->select([
                'poi.product_id',
                'po.id as purchase_order_id',
                DB::raw('COALESCE(po.received_date, DATE(po.created_at)) as po_sort_date'),
            ])
            ->get();

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $poId = (int) $row->purchase_order_id;
            $date = (string) $row->po_sort_date;
            if (
                ! isset($bestPoByProduct[$productId])
                || $date > $bestPoByProduct[$productId]['po_sort_date']
            ) {
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

        /** @var array<int, string> $poSortDates */
        $poSortDates = [];
        foreach ($bestPoByProduct as $info) {
            $poSortDates[$info['purchase_order_id']] = $info['po_sort_date'];
        }
        arsort($poSortDates);

        $ordered = [];
        foreach (array_keys($poSortDates) as $poId) {
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
}
