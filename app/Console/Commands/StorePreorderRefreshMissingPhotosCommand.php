<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StorePreorders\StorePreorderShopifyImageFollowUpService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'store-preorders:refresh-missing-photos')]
final class StorePreorderRefreshMissingPhotosCommand extends Command
{
    protected $signature = 'store-preorders:refresh-missing-photos';

    protected $description = 'Attach real Plamod photos (not hub/PDP “No image” placeholders) for store preorders still missing Shopify photos.';

    public function handle(StorePreorderShopifyImageFollowUpService $images): int
    {
        $result = $images->queueMissingPhotoCrawls();
        $this->table(['metric', 'value'], [
            ['missing_images', (string) $result->missing],
            ['pick_list_images_attached', (string) $result->attached],
            ['image_push_queued', (string) $result->imagePushQueued],
            ['photo_crawl_queued', (string) $result->queued],
        ]);

        if ($result->missing === 0) {
            $this->info('No store preorders are missing real images.');

            return self::SUCCESS;
        }

        if ($result->attached > 0) {
            $this->info('Attached Plamod pick-list images and queued Shopify image pushes.');
        }
        if ($result->queued > 0) {
            $this->info('Queued Plamod photo crawls for store preorders still missing real images.');
        }

        return self::SUCCESS;
    }
}
