<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductRepository;
use App\Models\ProductExternalAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ProductManualImageUploadService
{
    public const string SOURCE = 'manual_upload';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductExternalAssetRepository $assets,
        private readonly PlamodAssetFilenameService $assetRenamer,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{created:int, asset_ids:array<int,int>}
     */
    public function upload(string $productUuid, array $files): array
    {
        $product = $this->products->findByUuidOrFail($productUuid);

        $files = array_values(array_filter($files, static fn (mixed $f): bool => $f instanceof UploadedFile));
        if ($files === []) {
            return ['created' => 0, 'asset_ids' => []];
        }

        $disk = Storage::disk('local');
        $dir = 'manual_upload/images/'.(string) $product->uuid;

        // Append after the current max explicit sort_order (across sources).
        $current = $this->assets->listAllForProduct((int) $product->id);
        $maxSort = 0;
        foreach ($current as $a) {
            if (! $a instanceof ProductExternalAsset) {
                continue;
            }
            $n = is_int($a->sort_order) ? $a->sort_order : null;
            if ($n !== null && $n > $maxSort) {
                $maxSort = $n;
            }
        }

        $rows = [];
        $order = $maxSort;
        foreach ($files as $file) {
            $order++;
            $orig = trim((string) $file->getClientOriginalName());
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $ext = $ext !== '' ? $ext : 'jpg';

            $safeOrig = $orig !== '' ? basename(str_replace(['\\', '/'], '-', $orig)) : ('image.'.$ext);
            $storageName = (string) Str::uuid().'-'.$safeOrig;
            $storagePath = $disk->putFileAs($dir, $file, $storageName);

            $mime = $file->getMimeType();
            $size = $file->getSize();

            $sha = null;
            try {
                $abs = $disk->path($storagePath);
                if (is_string($abs) && $abs !== '' && is_file($abs)) {
                    $sha = hash_file('sha256', $abs);
                }
            } catch (\Throwable) {
                // best-effort
            }

            $rows[] = [
                'kind' => 'image',
                'storage_path' => $storagePath,
                'filename' => $safeOrig,
                'mime_type' => is_string($mime) && $mime !== '' ? $mime : null,
                'size_bytes' => is_int($size) ? $size : (is_numeric($size) ? (int) $size : null),
                'checksum_sha256' => is_string($sha) && $sha !== '' ? $sha : null,
                'sort_order' => $order,
                'shopify_enabled' => true,
            ];
        }

        $created = $this->assets->createForProduct((int) $product->id, self::SOURCE, $rows);

        if ($created !== []) {
            $this->assetRenamer->renameImageAssetsForProductUuid((string) $product->uuid);
        }

        return [
            'created' => count($created),
            'asset_ids' => array_values(array_map(static fn (ProductExternalAsset $a): int => (int) $a->id, $created)),
        ];
    }
}
