<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\StorePreorders\StorePreorderRepository;
use App\DTOs\StorePreorders\StorePreorderPlamodImagesOnlyResult;
use App\Models\Product;

final class StorePreorderPlamodImagesOnlyCleanupService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly ProductExternalAssetRepository $assets,
        private readonly StorePreorderShopifyQueueService $queue,
    ) {}

    public function cleanupAndQueue(bool $dryRun = false): StorePreorderPlamodImagesOnlyResult
    {
        $uuids = [];
        $removed = 0;
        foreach ($this->offers->listAll() as $offer) {
            $product = $offer->product;
            if (! $product instanceof Product) {
                continue;
            }

            $stripped = $this->stripHljAssets((int) $product->id, $dryRun);
            if ($stripped === 0) {
                continue;
            }

            $removed += $stripped;
            $uuid = trim((string) $product->uuid);
            if ($uuid !== '') {
                $uuids[] = $uuid;
            }
        }

        $uuids = array_values(array_unique($uuids));
        $queued = ($dryRun || $uuids === [])
            ? 0
            : $this->queue->queue($uuids, $this->queue->imagesOnly());

        return new StorePreorderPlamodImagesOnlyResult(
            count($uuids),
            $removed,
            $queued,
            $dryRun,
        );
    }

    private function stripHljAssets(int $productId, bool $dryRun): int
    {
        $existing = $this->assets->listForProduct($productId, StorePreorderUsesPlamodImagesOnly::CATALOG_SOURCE_HLJ);
        if ($existing === []) {
            return 0;
        }

        if (! $dryRun) {
            $this->assets->replaceForProduct($productId, StorePreorderUsesPlamodImagesOnly::CATALOG_SOURCE_HLJ, []);
        }

        return count($existing);
    }
}
