<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Inventory;

use App\Models\Product;
use App\Models\Shopify\ShopifyInventoryLevel;
use App\Services\Shopify\Admin\Sync\ShopifyErpSyncCoordinator;
use Illuminate\Support\Facades\DB;

final class ShopifyInventoryToProductsService
{
    public function __construct(
        private readonly ShopifyErpSyncCoordinator $coordinator,
    ) {}

    /**
     * @return array{matched:int, updated:int, skipped:int}
     */
    public function pullToAvailableQty(bool $syncLevelsFirst = true): array
    {
        if ($syncLevelsFirst) {
            $this->coordinator->sync('products');
            $this->coordinator->sync('inventory_levels');
        }

        $qtyBySku = $this->aggregateAvailableBySku();
        $matched = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($qtyBySku, &$matched, &$updated, &$skipped): void {
            foreach ($qtyBySku as $sku => $qty) {
                /** @var Product|null $product */
                $product = Product::query()->where('sku', $sku)->first();
                if ($product === null) {
                    $skipped++;

                    continue;
                }
                $matched++;
                $normalizedQty = max(0, (int) $qty);
                if ((int) ($product->available_qty ?? 0) !== $normalizedQty) {
                    $product->available_qty = $normalizedQty;
                    $product->save();
                    $updated++;
                }
            }
        });

        return compact('matched', 'updated', 'skipped');
    }

    /**
     * @return array<string, int>
     */
    private function aggregateAvailableBySku(): array
    {
        /** @var array<string, string> $itemSkus */
        $itemSkus = DB::table('shopify_product_variants as v')
            ->join('shopify_products as p', 'p.gid', '=', 'v.product_gid')
            ->where('p.status', '=', 'ACTIVE')
            ->whereNotNull('v.inventory_item_gid')
            ->where('v.inventory_item_gid', '!=', '')
            ->whereNotNull('v.sku')
            ->where('v.sku', '!=', '')
            ->pluck('v.sku', 'v.inventory_item_gid')
            ->all();

        $totals = [];
        ShopifyInventoryLevel::query()
            ->select(['inventory_item_gid', 'quantity_available'])
            ->orderBy('inventory_item_gid')
            ->chunk(500, function ($levels) use ($itemSkus, &$totals): void {
                foreach ($levels as $level) {
                    $sku = $itemSkus[$level->inventory_item_gid] ?? null;
                    if (! is_string($sku) || $sku === '') {
                        continue;
                    }
                    $qty = max(0, (int) ($level->quantity_available ?? 0));
                    $totals[$sku] = ($totals[$sku] ?? 0) + $qty;
                }
            });

        return $totals;
    }
}
