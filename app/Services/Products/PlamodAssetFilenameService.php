<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\DAL\Products\ProductRepository;
use App\Models\ProductExternalAsset;
use Illuminate\Support\Facades\Storage;

final class PlamodAssetFilenameService
{
    public const string SOURCE = PlamodAssetSyncService::SOURCE;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductExternalContentRepository $contents,
        private readonly ProductExternalAssetRepository $assets,
        private readonly AssetFilenameService $filenames,
    ) {}

    /**
     * @return array{renamed:int, skipped:int, missing_files:int, errors:array<int,string>}
     */
    public function renameImageAssetsForProductUuid(string $productUuid): array
    {
        $product = $this->products->findByUuidOrFail($productUuid);
        $disk = Storage::disk('local');

        $title = $this->resolveTitleForProduct($product->id, $product->description);
        $slug = $this->filenames->buildTitleSlug($title);

        $assets = $this->assets->listForProduct($product->id, self::SOURCE);
        $imageAssets = array_values(array_filter($assets, static function (ProductExternalAsset $a): bool {
            if ($a->kind === 'image') return true;
            return str_starts_with((string) ($a->mime_type ?? ''), 'image/');
        }));

        $renamed = 0;
        $skipped = 0;
        $missing = 0;
        $errors = [];

        $index = 0;
        foreach ($imageAssets as $a) {
            $index++;

            $ext = $this->detectExtension($a);
            $targetFilename = $this->filenames->buildSeoFilename($slug, $index, (int) $a->id, $ext);

            $dir = trim((string) dirname((string) $a->storage_path), '.');
            $dir = $dir === '' ? '' : $dir;
            $targetStoragePath = ($dir !== '' ? ($dir.'/') : '').$targetFilename;

            if ($a->filename === $targetFilename && $a->storage_path === $targetStoragePath) {
                $skipped++;
                continue;
            }

            if (! $disk->exists($a->storage_path)) {
                $missing++;
                $errors[] = "missing_file asset_id={$a->id} path={$a->storage_path}";
                continue;
            }

            try {
                $disk->move($a->storage_path, $targetStoragePath);
                $a->filename = $targetFilename;
                $a->storage_path = $targetStoragePath;
                $a->save();
                $renamed++;
            } catch (\Throwable $e) {
                $errors[] = "rename_failed asset_id={$a->id} err=".$e->getMessage();
            }
        }

        return [
            'renamed' => $renamed,
            'skipped' => $skipped,
            'missing_files' => $missing,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, string>  $productUuids
     * @return array{renamed_assets:int, products:array<string, array{renamed:int, skipped:int, missing_files:int, errors:array<int,string>}>}
     */
    public function bulkRename(array $productUuids): array
    {
        $productUuids = array_values(array_unique(array_filter(array_map('strval', $productUuids), static fn (string $v): bool => trim($v) !== '')));

        $totalRenamed = 0;
        $per = [];
        foreach ($productUuids as $uuid) {
            try {
                $res = $this->renameImageAssetsForProductUuid($uuid);
                $per[$uuid] = $res;
                $totalRenamed += (int) $res['renamed'];
            } catch (\Throwable $e) {
                $per[$uuid] = [
                    'renamed' => 0,
                    'skipped' => 0,
                    'missing_files' => 0,
                    'errors' => ['product_failed: '.$e->getMessage()],
                ];
            }
        }

        return [
            'renamed_assets' => $totalRenamed,
            'products' => $per,
        ];
    }

    private function resolveTitleForProduct(int $productId, string $fallbackName): string
    {
        $content = $this->contents->findForProduct($productId, 'hlj')
            ?? $this->contents->findForProduct($productId, self::SOURCE);

        $title = $content?->title;
        $title = is_string($title) ? trim($title) : '';
        if ($title !== '') {
            return $title;
        }

        $fallbackName = trim($fallbackName);
        return $fallbackName !== '' ? $fallbackName : 'product-image';
    }

    private function detectExtension(ProductExternalAsset $asset): string
    {
        $ext = pathinfo((string) $asset->storage_path, PATHINFO_EXTENSION);
        if ($ext !== '') return $ext;
        $ext = pathinfo((string) $asset->filename, PATHINFO_EXTENSION);
        if ($ext !== '') return $ext;

        $mime = (string) ($asset->mime_type ?? '');
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };
    }
}






