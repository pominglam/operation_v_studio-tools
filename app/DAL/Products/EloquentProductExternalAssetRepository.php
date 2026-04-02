<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\ProductExternalAsset;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class EloquentProductExternalAssetRepository implements ProductExternalAssetRepository
{
    public function replaceForProduct(int $productId, string $source, array $assets): array
    {
        $this->ensureStoragePathsAreWorldReadable($assets);

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
                    // Default: export images unless user disables in UI.
                    'shopify_enabled' => true,
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

    public function setShopifyEnabled(int $id, bool $enabled): void
    {
        ProductExternalAsset::query()
            ->where('id', '=', $id)
            ->update(['shopify_enabled' => $enabled]);
    }

    public function createForProduct(int $productId, string $source, array $assets): array
    {
        $source = trim($source);
        if ($source === '' || $assets === []) {
            return [];
        }

        $this->ensureStoragePathsAreWorldReadable($assets);

        return DB::transaction(function () use ($productId, $source, $assets): array {
            $created = [];
            foreach ($assets as $a) {
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
                    'sort_order' => $a['sort_order'] ?? null,
                    'shopify_enabled' => array_key_exists('shopify_enabled', $a) ? (bool) $a['shopify_enabled'] : true,
                ]);
            }

            return $created;
        });
    }

    /**
     * Ensure newly-written files/directories under `storage/app/private` are readable by
     * the `shopify_images_php` worker user (app).
     *
     * Without this, some crawlers can create `0700` directories (umask 077) owned by root,
     * causing `/shopify-images/*` to 404 even though the DB row exists.
     *
     * @param  array<int, array<string, mixed>>  $assets
     */
    private function ensureStoragePathsAreWorldReadable(array $assets): void
    {
        if ($assets === []) {
            return;
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');
        foreach ($assets as $a) {
            $path = is_array($a) ? ($a['storage_path'] ?? null) : null;
            $path = is_string($path) ? trim($path) : '';
            if ($path === '') {
                continue;
            }
            $this->ensureStoragePathReadable($disk, $path);
        }
    }

    private function ensureStoragePathReadable(FilesystemAdapter $disk, string $storagePath): void
    {
        $storagePath = trim($storagePath);
        if ($storagePath === '') {
            return;
        }

        try {
            $root = $disk->path('');
            $root = is_string($root) ? rtrim($root, DIRECTORY_SEPARATOR) : '';

            $abs = $disk->path($storagePath);
            if (! is_string($abs) || trim($abs) === '') {
                return;
            }

            if ($root !== '' && ! str_starts_with($abs, $root.DIRECTORY_SEPARATOR) && $abs !== $root) {
                return;
            }

            if (is_file($abs)) {
                @chmod($abs, 0644);
            } elseif (is_dir($abs)) {
                @chmod($abs, 0755);
            }

            $dir = is_dir($abs) ? $abs : dirname($abs);
            for ($i = 0; $i < 50; $i++) {
                if (! is_dir($dir)) {
                    break;
                }

                if ($root !== '' && ! str_starts_with($dir, $root)) {
                    break;
                }

                @chmod($dir, 0755);

                if ($root !== '' && rtrim($dir, DIRECTORY_SEPARATOR) === $root) {
                    break;
                }

                $next = dirname($dir);
                if ($next === $dir) {
                    break;
                }
                $dir = $next;
            }
        } catch (\Throwable) {
            // Best-effort only.
        }
    }
}