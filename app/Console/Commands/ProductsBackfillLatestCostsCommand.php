<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\ProductLatestCostCacheService;
use Illuminate\Console\Command;

final class ProductsBackfillLatestCostsCommand extends Command
{
    protected $signature = 'products:backfill-latest-costs {--yes : Do not prompt; assume yes}';

    protected $description = 'Recomputes products.latest_unit_cost and products.latest_landed_unit_cost from latest PO data for all products.';

    public function handle(ProductLatestCostCacheService $service): int
    {
        $yes = (bool) $this->option('yes');

        $this->warn('This will update cached latest costs for ALL products based on purchase order history.');
        if (! $yes && ! $this->confirm('Proceed?', false)) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $res = $service->recomputeAll();
        $this->info("Matched: {$res['matched']}, Updated: {$res['updated']}.");

        return self::SUCCESS;
    }
}

