<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shopify\Admin\Write\ShopifyStorefrontPilotCollectionService;
use Illuminate\Console\Command;

final class ShopifyStorePreordersCollectionCommand extends Command
{
    protected $signature = 'shopify:store-preorders-collection';

    protected $description = 'Create or update the customer Pre-orders smart collection (not added to nav)';

    public function handle(ShopifyStorefrontPilotCollectionService $collections): int
    {
        $row = $collections->ensureStorePreordersCollection();
        $this->info($row['url']);
        $this->table(
            ['Handle', 'Title', 'Products', 'GID'],
            [[$row['handle'], $row['title'], (string) $row['product_count'], $row['gid']]],
        );

        return self::SUCCESS;
    }
}
