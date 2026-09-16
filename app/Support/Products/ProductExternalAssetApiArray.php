<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\ProductExternalAsset;

final class ProductExternalAssetApiArray
{
    /**
     * @return array<string, mixed>
     */
    public static function from(ProductExternalAsset $asset): array
    {
        $id = (int) $asset->id;

        return [
            'id' => $id,
            'source' => (string) $asset->source,
            'kind' => (string) $asset->kind,
            'filename' => (string) $asset->filename,
            'mime_type' => $asset->mime_type,
            'size_bytes' => $asset->size_bytes,
            'origin_url' => $asset->origin_url,
            'origin_width' => $asset->origin_width,
            'origin_height' => $asset->origin_height,
            'checksum_sha256' => $asset->checksum_sha256,
            'sort_order' => $asset->sort_order,
            'shopify_enabled' => (bool) ($asset->shopify_enabled ?? true),
            'download_url' => ProductExternalAssetUrlBuilder::downloadUrl($id),
            'view_url' => ProductExternalAssetUrlBuilder::viewUrl($id),
            'thumb_url' => $asset->kind === 'image'
                ? ProductExternalAssetUrlBuilder::thumbUrl($id)
                : null,
        ];
    }
}
