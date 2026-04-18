<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalAssetRepository;
use App\Models\ProductExternalAsset;

final class ProductExternalAssetShopifyPreferenceService
{
    public function __construct(private readonly ProductExternalAssetRepository $assets) {}

    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function setShopifyEnabled(int $assetId, bool $enabled): ProductExternalAsset
    {
        $asset = $this->assets->findById($assetId);
        if (! $asset instanceof ProductExternalAsset) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Asset not found.');
        }

        $this->assets->setShopifyEnabled($assetId, $enabled);

        // Re-fetch (keeps controller thin and returns authoritative current state).
        $updated = $this->assets->findById($assetId);
        if (! $updated instanceof ProductExternalAsset) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Asset not found after update.');
        }

        return $updated;
    }
}
