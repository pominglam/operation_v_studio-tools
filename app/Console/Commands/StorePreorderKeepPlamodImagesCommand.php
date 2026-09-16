<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StorePreorders\StorePreorderPlamodImagesOnlyCleanupService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'store-preorders:keep-plamod-images')]
final class StorePreorderKeepPlamodImagesCommand extends Command
{
    protected $signature = 'store-preorders:keep-plamod-images {--dry-run : Count HLJ assets without deleting or pushing}';

    protected $description = 'Remove HLJ photos from store-preorder products and push remaining Plamod images to Shopify.';

    public function handle(StorePreorderPlamodImagesOnlyCleanupService $cleanup): int
    {
        $result = $cleanup->cleanupAndQueue((bool) $this->option('dry-run'));
        $this->table(['metric', 'value'], [
            ['products_cleaned', (string) $result->productsCleaned],
            ['hlj_assets_removed', (string) $result->assetsRemoved],
            ['image_push_queued', (string) $result->imagePushQueued],
            ['dry_run', $result->dryRun ? 'yes' : 'no'],
        ]);

        if ($result->dryRun) {
            $this->info('Dry run — ERP and Shopify were not changed.');

            return self::SUCCESS;
        }

        if ($result->productsCleaned === 0) {
            $this->info('No store-preorder products had HLJ photos.');

            return self::SUCCESS;
        }

        $this->info('Removed HLJ photos and queued Shopify image-only updates.');

        return self::SUCCESS;
    }
}
