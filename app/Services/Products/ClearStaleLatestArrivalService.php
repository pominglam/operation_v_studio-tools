<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Services\Shopify\Admin\Write\ShopifyLatestArrivalTagRemoverService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ClearStaleLatestArrivalService
{
    public const int STALE_PO_WEEKS = 4;

    public function __construct(
        private readonly ShopifyLatestArrivalTagRemoverService $shopifyTagRemover,
    ) {}

    /**
     * Clears latest_arrival on products linked only to purchase orders older than four weeks
     * (skips products that also appear on a PO within the last four weeks), then removes the
     * Shopify "latest arrival" tag on mirrored products that actually changed.
     * PO age uses received_date when set; otherwise created_at.
     *
     * @return array{
     *   purchase_orders_matched: int,
     *   products_cleared: int,
     *   cutoff_date: string,
     *   shopify_tags_removed: int,
     *   shopify_skipped_no_gid: int,
     *   shopify_tag_removals_failed: int
     * }
     */
    public function clear(): array
    {
        $cutoff = $this->cutoffDate();

        $oldPoIds = $this->purchaseOrderIdsOlderThan($cutoff);

        if ($oldPoIds === []) {
            return $this->emptySummary($cutoff);
        }

        $productIds = $this->productIdsOnPurchaseOrders($oldPoIds);
        $productIds = $this->excludeProductIdsOnRecentPurchaseOrders($productIds, $cutoff);

        if ($productIds === []) {
            return array_merge($this->emptySummary($cutoff), [
                'purchase_orders_matched' => count($oldPoIds),
            ]);
        }

        $productsToClear = Product::query()
            ->whereIn('id', $productIds)
            ->where('latest_arrival', '=', true)
            ->get();

        if ($productsToClear->isEmpty()) {
            return array_merge($this->emptySummary($cutoff), [
                'purchase_orders_matched' => count($oldPoIds),
            ]);
        }

        $productsChanged = collect();
        foreach ($productsToClear as $product) {
            if (! $product->latest_arrival) {
                continue;
            }

            $updated = Product::query()
                ->where('id', '=', $product->id)
                ->where('latest_arrival', '=', true)
                ->update(['latest_arrival' => false]);

            if ($updated === 1) {
                $productsChanged->push($product);
            }
        }

        $shopifyStats = [
            'shopify_tags_removed' => 0,
            'shopify_skipped_no_gid' => 0,
            'shopify_tag_removals_failed' => 0,
        ];

        if ($productsChanged->isNotEmpty()) {
            $this->shopifyTagRemover->assertCanRemoveForProducts($productsChanged);
            $shopifyStats = $this->shopifyTagRemover->removeFromProducts($productsChanged);

            foreach ($productsChanged as $product) {
                $product->latest_arrival = false;
            }
        }

        return [
            'purchase_orders_matched' => count($oldPoIds),
            'products_cleared' => $productsChanged->count(),
            'cutoff_date' => $cutoff->toDateString(),
            'shopify_tags_removed' => $shopifyStats['shopify_tags_removed'],
            'shopify_skipped_no_gid' => $shopifyStats['shopify_skipped_no_gid'],
            'shopify_tag_removals_failed' => $shopifyStats['shopify_tag_removals_failed'],
        ];
    }

    public function cutoffDate(): Carbon
    {
        return now()->subWeeks(self::STALE_PO_WEEKS)->startOfDay();
    }

    /**
     * @return list<int>
     */
    private function purchaseOrderIdsOlderThan(Carbon $cutoff): array
    {
        return PurchaseOrder::query()
            ->where(function ($q) use ($cutoff): void {
                $q->where(function ($inner) use ($cutoff): void {
                    $inner->whereNotNull('received_date')
                        ->whereDate('received_date', '<', $cutoff);
                })->orWhere(function ($inner) use ($cutoff): void {
                    $inner->whereNull('received_date')
                        ->where('created_at', '<', $cutoff);
                });
            })
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function purchaseOrderIdsWithinCutoff(Carbon $cutoff): array
    {
        return PurchaseOrder::query()
            ->where(function ($q) use ($cutoff): void {
                $q->where(function ($inner) use ($cutoff): void {
                    $inner->whereNotNull('received_date')
                        ->whereDate('received_date', '>=', $cutoff);
                })->orWhere(function ($inner) use ($cutoff): void {
                    $inner->whereNull('received_date')
                        ->where('created_at', '>=', $cutoff);
                });
            })
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $purchaseOrderIds
     * @return list<int>
     */
    private function productIdsOnPurchaseOrders(array $purchaseOrderIds): array
    {
        if ($purchaseOrderIds === []) {
            return [];
        }

        /** @var array<int, int|string> $productIds */
        $productIds = DB::table('purchase_order_items')
            ->whereIn('purchase_order_id', $purchaseOrderIds)
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id')
            ->all();

        return array_values(array_filter(array_map(
            static fn (int|string $id): int => (int) $id,
            $productIds,
        ), static fn (int $id): bool => $id > 0));
    }

    /**
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function excludeProductIdsOnRecentPurchaseOrders(array $productIds, Carbon $cutoff): array
    {
        if ($productIds === []) {
            return [];
        }

        $recentPoIds = $this->purchaseOrderIdsWithinCutoff($cutoff);
        if ($recentPoIds === []) {
            return $productIds;
        }

        $onRecentPo = $this->productIdsOnPurchaseOrders($recentPoIds);
        if ($onRecentPo === []) {
            return $productIds;
        }

        $recentSet = array_fill_keys($onRecentPo, true);

        return array_values(array_filter(
            $productIds,
            static fn (int $id): bool => ! isset($recentSet[$id]),
        ));
    }

    /**
     * @return array{
     *   purchase_orders_matched: int,
     *   products_cleared: int,
     *   cutoff_date: string,
     *   shopify_tags_removed: int,
     *   shopify_skipped_no_gid: int,
     *   shopify_tag_removals_failed: int
     * }
     */
    private function emptySummary(Carbon $cutoff): array
    {
        return [
            'purchase_orders_matched' => 0,
            'products_cleared' => 0,
            'cutoff_date' => $cutoff->toDateString(),
            'shopify_tags_removed' => 0,
            'shopify_skipped_no_gid' => 0,
            'shopify_tag_removals_failed' => 0,
        ];
    }
}
