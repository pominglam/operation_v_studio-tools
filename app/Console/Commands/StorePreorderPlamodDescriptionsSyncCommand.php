<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StorePreorders\StorePreorderPlamodDescriptionSyncService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'store-preorders:sync-plamod-descriptions')]
final class StorePreorderPlamodDescriptionsSyncCommand extends Command
{
    protected $signature = 'store-preorders:sync-plamod-descriptions {--push : Push updated listings to Shopify}';

    protected $description = 'Grab Plamod full PDP descriptions for store-preorder SKUs and optionally push them to Shopify.';

    public function handle(StorePreorderPlamodDescriptionSyncService $sync): int
    {
        $result = $sync->syncAll(pushShopify: (bool) $this->option('push'));
        $this->table(['metric', 'value'], [
            ['attempted', (string) $result->attempted],
            ['updated', (string) count($result->updatedSkus)],
            ['failed', (string) count($result->failedSkus)],
            ['shopify_pushed', (string) count($result->pushedSkus)],
        ]);
        if ($result->updatedSkus !== []) {
            $this->info('Updated: '.implode(', ', $result->updatedSkus));
        }
        if ($result->failedSkus !== []) {
            $this->warn('No Plamod description: '.implode(', ', $result->failedSkus));
        }

        return self::SUCCESS;
    }
}
