<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DAL\Products\ProductRepository;
use App\Support\Products\Storefront\ProductStorefrontClassifier;
use Illuminate\Console\Command;

final class ProductsStorefrontClassifyCommand extends Command
{
    protected $signature = 'products:storefront-classify
        {--department= : Limit output to one department (e.g. tapes, decals)}
        {--warnings-only : Show only rows with warnings}
        {--sku= : Filter to a single SKU}';

    protected $description = 'Dry-run storefront classification (ts:* tags) for ERP products.';

    public function handle(
        ProductRepository $products,
        ProductStorefrontClassifier $classifier,
    ): int {
        $departmentFilter = is_string($this->option('department')) ? trim($this->option('department')) : '';
        $warningsOnly = (bool) $this->option('warnings-only');
        $skuFilter = is_string($this->option('sku')) ? strtoupper(trim($this->option('sku'))) : '';

        $rows = [];
        foreach ($products->listForExport(null, [], null, 'asc') as $product) {
            $sku = strtoupper(trim((string) $product->sku));
            if ($skuFilter !== '' && $sku !== $skuFilter) {
                continue;
            }

            $classification = $classifier->classify($product);
            if ($departmentFilter !== '' && $classification->department !== $departmentFilter) {
                continue;
            }
            if ($warningsOnly && $classification->warnings === []) {
                continue;
            }

            $rows[] = [
                $product->sku,
                $classification->department ?? '-',
                implode(', ', $classification->storefrontTags) ?: '-',
                implode(', ', $classification->shopifyTags) ?: '-',
                implode(', ', $classification->warnings) ?: '-',
            ];
        }

        if ($rows === []) {
            $this->info('No matching products.');

            return self::SUCCESS;
        }

        $this->table(
            ['SKU', 'Department', 'Storefront tags', 'Shopify tags', 'Warnings'],
            $rows,
        );
        $this->info('Rows: '.count($rows));

        return self::SUCCESS;
    }
}
