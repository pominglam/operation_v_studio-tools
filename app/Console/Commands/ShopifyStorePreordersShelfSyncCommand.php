<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StorePreorders\StorePreorderShopifyShelfSyncService;
use Illuminate\Console\Command;

final class ShopifyStorePreordersShelfSyncCommand extends Command
{
    protected $signature = 'shopify:store-preorders-shelf-sync';

    protected $description = 'Untag or delete Shopify store-preorder leftovers that are no longer ERP offers';

    public function handle(StorePreorderShopifyShelfSyncService $sync): int
    {
        $result = $sync->syncAll();
        $this->table(
            ['Kept', 'Retagged', 'Untagged', 'Deleted', 'Failed'],
            [[
                (string) $result->kept,
                (string) $result->retagged,
                (string) $result->untagged,
                (string) $result->deleted,
                (string) $result->failed,
            ]],
        );
        foreach ($result->failures as $failure) {
            $this->warn($failure);
        }

        return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
