<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\ProductExternalAsset;
use Illuminate\Support\Facades\DB;

final class EloquentProductExternalAssetRepository implements ProductExternalAssetRepository
{
    public function replaceForProduct(int $productId, string $source, array $assets): array
    {
        return DB::transaction(function () use ($productId, $source, $assets): array {
            ProductExternalAsset::query()
                ->where('product_id', $productId)
                ->where('source', $source)
                ->delete();

            $created = [];
            $order = 0;
            foreach ($assets as $a) {
                $order++;
                $created[] = ProductExternalAsset::query()->create([
                    'product_id' => $productId,
                    'source' => $source,
                    'kind' => $a['kind'],
                    'storage_path' => $a['storage_path'],
                    'filename' => $a['filename'],
                    'mime_type' => $a['mime_type'] ?? null,
                    'size_bytes' => $a['size_bytes'] ?? null,
                    'origin_url' => $a['origin_url'] ?? null,
                    'origin_width' => $a['origin_width'] ?? null,
                    'origin_height' => $a['origin_height'] ?? null,
                    'checksum_sha256' => $a['checksum_sha256'] ?? null,
                    'sort_order' => $order,
                ]);
            }

            return $created;
        });
    }

    public function listForProduct(int $productId, string $source): array
    {
        /** @var array<int, ProductExternalAsset> $assets */
        $assets = ProductExternalAsset::query()
            ->where('product_id', $productId)
            ->where('source', $source)
            // Keep explicit sort first; fall back to id for stability.
            // NULL sort_order should appear last.
            ->orderByRaw('sort_order is null')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();

        return $assets;
    }

    public function listAllForProduct(int $productId): array
    {
        /** @var array<int, ProductExternalAsset> $assets */
        $assets = ProductExternalAsset::query()
            ->where('product_id', $productId)
            ->orderBy('source')
            ->orderByRaw('sort_order is null')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();

        return $assets;
    }

    public function updateSortOrders(array $assetIdToSortOrder): void
    {
        $assetIdToSortOrder = array_filter(
            $assetIdToSortOrder,
            static fn (mixed $v, mixed $k): bool => is_int($k) && $k > 0 && is_int($v) && $v >= 0,
            ARRAY_FILTER_USE_BOTH
        );

        if ($assetIdToSortOrder === []) {
            return;
        }

        DB::transaction(function () use ($assetIdToSortOrder): void {
            foreach ($assetIdToSortOrder as $id => $sortOrder) {
                ProductExternalAsset::query()
                    ->where('id', '=', $id)
                    ->update(['sort_order' => $sortOrder]);
            }
        });
    }

    public function findById(int $id): ?ProductExternalAsset
    {
        /** @var ProductExternalAsset|null $asset */
        $asset = ProductExternalAsset::query()->find($id);

        return $asset;
    }
}


