<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductRepository;
use App\Models\ProductExternalAsset;

final class ProductExternalAssetShopifyPreferenceService
{
    public function __construct(
        private readonly ProductExternalAssetRepository $assets,
        private readonly ProductRepository $products,
    ) {}

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
        $asset->shopify_enabled = $enabled;

        return $asset;
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function setShopifyEnabledForProduct(string $productUuid, array $ids, bool $enabled): int
    {
        $product = $this->products->findByUuidOrFail($productUuid);

        return $this->assets->setShopifyEnabledForProductIds((int) $product->id, $ids, $enabled);
    }
}
