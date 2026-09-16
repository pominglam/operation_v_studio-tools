<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\Products\ProductExternalAssetRepository;
use App\Models\Product;
use App\Services\Products\PlamodPlaceholderImageDetector;

final class StorePreorderRealShopifyImagePolicy
{
    public function __construct(
        private readonly ProductExternalAssetRepository $assets,
        private readonly PlamodPlaceholderImageDetector $placeholders,
    ) {}

    public function hasRealImages(string $productUuid): bool
    {
        $productUuid = trim($productUuid);
        if ($productUuid === '') {
            return false;
        }

        $product = Product::query()->where('uuid', '=', $productUuid)->first();
        if ($product === null) {
            return false;
        }

        foreach ($product->shopifyImageAssets as $asset) {
            if (! $this->placeholders->isPlaceholderAsset($asset)) {
                return true;
            }
        }

        return false;
    }

    public function stripPlaceholders(string $productUuid): int
    {
        $productUuid = trim($productUuid);
        if ($productUuid === '') {
            return 0;
        }

        $product = Product::query()->where('uuid', '=', $productUuid)->first();
        if ($product === null) {
            return 0;
        }

        $removed = 0;
        foreach ($this->assets->listAllForProduct((int) $product->id) as $asset) {
            if ($asset->kind !== 'image' || ! $this->placeholders->isPlaceholderAsset($asset)) {
                continue;
            }
            $this->assets->deleteById((int) $asset->id);
            $removed++;
        }

        return $removed;
    }
}
