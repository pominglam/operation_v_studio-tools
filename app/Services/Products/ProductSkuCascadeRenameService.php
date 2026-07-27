<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class ProductSkuCascadeRenameService
{
    /** @var array<int, string> */
    private const SKU_TABLES = [
        'purchase_order_items',
        'inventory_check_items',
        'job_batch_items',
        'price_research_quote_reports',
        'price_research_run_logs',
        'shopify_inventory_items',
        'shopify_order_line_items',
        'shopify_product_variants',
        'plamod_preorders',
    ];

    public function rename(string $oldSku, string $newSku): void
    {
        $oldSku = trim($oldSku);
        $newSku = trim($newSku);
        if ($oldSku === '' || $newSku === '' || $oldSku === $newSku) {
            return;
        }

        if (Product::query()->where('sku', '=', $newSku)->exists()) {
            throw new \InvalidArgumentException("Cannot rename {$oldSku} to {$newSku}: target SKU already exists.");
        }

        DB::transaction(function () use ($oldSku, $newSku): void {
            foreach (self::SKU_TABLES as $table) {
                DB::table($table)->where('sku', '=', $oldSku)->update([
                    'sku' => $newSku,
                    'updated_at' => now(),
                ]);
            }

            Product::query()->where('sku', '=', $oldSku)->update([
                'sku' => $newSku,
                'updated_at' => now(),
            ]);
        });
    }
}
