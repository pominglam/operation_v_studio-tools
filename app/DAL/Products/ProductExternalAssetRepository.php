<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\ProductExternalAsset;

interface ProductExternalAssetRepository
{
    /**
     * @param  array<int, array{kind: string, storage_path: string, filename: string, mime_type?: string|null, size_bytes?: int|null}>  $assets
     * @return array<int, ProductExternalAsset>
     */
    public function replaceForProduct(int $productId, string $source, array $assets): array;

    /**
     * @return array<int, ProductExternalAsset>
     */
    public function listForProduct(int $productId, string $source): array;

    /**
     * @param  array<int, int>  $assetIdToSortOrder
     */
    public function updateSortOrders(array $assetIdToSortOrder): void;

    public function findById(int $id): ?ProductExternalAsset;
}


