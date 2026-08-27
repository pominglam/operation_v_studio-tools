<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Products\ProductWorkshopTaxonomyResolver;
use App\Support\Products\AgentTestSkuGuard;
use App\Support\Products\ProductTaxonomyFields;
use Illuminate\Console\Command;

final class ProductsTaxonomyBackfillWorkshopCommand extends Command
{
    protected $signature = 'products:taxonomy-backfill-workshop
        {--dry-run : Report counts without writing ERP rows}';

    protected $description = 'Backfill workshop_shelf and workshop_facets on products from storefront classifier rules';

    public function handle(ProductWorkshopTaxonomyResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;

        Product::query()->orderBy('id')->chunkById(200, function ($products) use ($resolver, $dryRun, &$updated, &$skipped): void {
            foreach ($products as $product) {
                if (AgentTestSkuGuard::isAgentTestSku((string) $product->sku, (string) $product->description)) {
                    $skipped++;

                    continue;
                }

                if (is_string($product->workshop_shelf) && trim($product->workshop_shelf) !== '') {
                    $skipped++;

                    continue;
                }

                $workshop = $resolver->resolve($product);
                if ($workshop['workshop_shelf'] === null) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    $product->workshop_shelf = $workshop['workshop_shelf'];
                    $product->workshop_facets = ProductTaxonomyFields::normalizeFacets($workshop['workshop_facets']);
                    $product->save();
                }

                $updated++;
            }
        });

        $this->info($dryRun ? 'Dry-run workshop backfill:' : 'Workshop taxonomy backfill:');
        $this->info("Updated: {$updated}");
        $this->info("Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
