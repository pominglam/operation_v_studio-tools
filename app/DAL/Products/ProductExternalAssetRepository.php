<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\ProductExternalAsset;

interface ProductExternalAssetRepository
{
    /**
     * @param  array<int, array{
     *   kind: string,
     *   storage_path: string,
     *   filename: string,
     *   mime_type?: string|null,
     *   size_bytes?: int|null,
     *   origin_url?: string|null,
     *   origin_width?: int|null,
     *   origin_height?: int|null,
     *   checksum_sha256?: string|null
     * }>  $assets
     * @return array<int, ProductExternalAsset>
     */
    public function replaceForProduct(int $productId, string $source, array $assets): array;

    /**
     * @return array<int, ProductExternalAsset>
     */
    public function listForProduct(int $productId, string $source): array;

    /**
     * @return array<int, ProductExternalAsset>
     */
    public function listAllForProduct(int $productId): array;

    /**
     * @param  array<int, int>  $assetIdToSortOrder
     */
    public function updateSortOrders(array $assetIdToSortOrder): void;

    public function findById(int $id): ?ProductExternalAsset;

    public function setShopifyEnabled(int $id, bool $enabled): void;

    public function deleteById(int $id): void;

    /**
     * Create additional assets for a product (append; does not delete existing rows).
     *
     * @param  array<int, array{
     *   kind: string,
     *   storage_path: string,
     *   filename: string,
     *   mime_type?: string|null,
     *   size_bytes?: int|null,
     *   origin_url?: string|null,
     *   origin_width?: int|null,
     *   origin_height?: int|null,
     *   checksum_sha256?: string|null,
     *   sort_order?: int|null,
     *   shopify_enabled?: bool|null
     * }>  $assets
     * @return array<int, ProductExternalAsset>
     */
    public function createForProduct(int $productId, string $source, array $assets): array;
}
