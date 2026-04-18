<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\DAL\Products\ProductRepository;
use App\Models\ProductExternalAsset;
use Illuminate\Filesystem\FilesystemAdapter;
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

        // Rename ALL image assets for the product, not just Plamod source.
        // This ensures hidden/off images still get SEO filenames.
        $assets = $this->assets->listAllForProduct($product->id);
        $imageAssets = array_values(array_filter($assets, static function (ProductExternalAsset $a): bool {
            if ($a->kind === 'image') {
                return true;
            }

            return str_starts_with((string) ($a->mime_type ?? ''), 'image/');
        }));

        $renamed = 0;
        $skipped = 0;
        $missing = 0;
        $errors = [];

        $assignedTargetPaths = [];
        $indexSeed = 0;
        foreach ($imageAssets as $a) {
            $indexSeed++;
            $ext = $this->detectExtension($a);

            $dir = trim((string) dirname((string) $a->storage_path), '.');
            $dir = $dir === '' ? '' : $dir;

            [$index, $targetFilename, $targetStoragePath] = $this->pickUniqueTarget(
                slug: $slug,
                ext: $ext,
                dir: $dir,
                asset: $a,
                startIndex: $indexSeed,
                assignedTargetPaths: $assignedTargetPaths,
                disk: $disk,
            );

            if ($a->filename === $targetFilename && $a->storage_path === $targetStoragePath) {
                $skipped++;
                $assignedTargetPaths[$targetStoragePath] = true;

                continue;
            }

            if (! $disk->exists($a->storage_path)) {
                $missing++;
                $errors[] = "missing_file asset_id={$a->id} path={$a->storage_path}";

                continue;
            }

            try {
                $moved = $this->moveOnDisk($disk, (string) $a->storage_path, $targetStoragePath);
                if (! $moved) {
                    $errors[] = "rename_failed asset_id={$a->id} err=move_failed src={$a->storage_path} dst={$targetStoragePath}";

                    continue;
                }
                $this->ensurePathIsReadableForShopifyImages($disk, $targetStoragePath);
                $a->filename = $targetFilename;
                $a->storage_path = $targetStoragePath;
                $a->save();
                $renamed++;
                $assignedTargetPaths[$targetStoragePath] = true;
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
        // Use the product name shown in the grid (products.description) when available.
        // External content titles can differ and should not affect SEO filenames.
        $fallbackName = trim($fallbackName);
        if ($fallbackName !== '') {
            return $fallbackName;
        }

        $content = $this->contents->findForProduct($productId, 'hlj')
            ?? $this->contents->findForProduct($productId, self::SOURCE);

        $title = $content?->title;
        $title = is_string($title) ? trim($title) : '';
        if ($title !== '') {
            return $title;
        }

        return 'product-image';
    }

    private function detectExtension(ProductExternalAsset $asset): string
    {
        $ext = pathinfo((string) $asset->storage_path, PATHINFO_EXTENSION);
        if ($ext !== '') {
            return $ext;
        }
        $ext = pathinfo((string) $asset->filename, PATHINFO_EXTENSION);
        if ($ext !== '') {
            return $ext;
        }

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

    /**
     * @param  array<string, true>  $assignedTargetPaths
     * @return array{0:int, 1:string, 2:string} index, filename, storagePath
     */
    private function pickUniqueTarget(
        string $slug,
        string $ext,
        string $dir,
        ProductExternalAsset $asset,
        int $startIndex,
        array $assignedTargetPaths,
        \Illuminate\Contracts\Filesystem\Filesystem $disk,
    ): array {
        $index = max(1, $startIndex);

        // Safety guard: 2-digit index is for UX; allow bumping beyond 99 if needed.
        for ($attempt = 0; $attempt < 500; $attempt++) {
            $targetFilename = $this->filenames->buildSeoFilename($slug, $index, (int) $asset->id, $ext);
            $targetStoragePath = ($dir !== '' ? ($dir.'/') : '').$targetFilename;

            $alreadyCorrect = $asset->filename === $targetFilename && $asset->storage_path === $targetStoragePath;
            if ($alreadyCorrect) {
                return [$index, $targetFilename, $targetStoragePath];
            }

            if (isset($assignedTargetPaths[$targetStoragePath]) || $disk->exists($targetStoragePath)) {
                $index++;

                continue;
            }

            return [$index, $targetFilename, $targetStoragePath];
        }

        // Extremely unlikely; fall back to a very large index instead of risking overwrite.
        $index = 9999;
        $targetFilename = $this->filenames->buildSeoFilename($slug, $index, (int) $asset->id, $ext);
        $targetStoragePath = ($dir !== '' ? ($dir.'/') : '').$targetFilename;

        return [$index, $targetFilename, $targetStoragePath];
    }

    private function ensurePathIsReadableForShopifyImages(FilesystemAdapter $disk, string $storagePath): void
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

            // Ensure file is world-readable (shopify_images_php may run as a different user than the main app container).
            if (is_file($abs)) {
                @chmod($abs, 0644);
            }

            // Ensure all directories up to the disk root are traversable/readable.
            $dir = dirname($abs);
            for ($i = 0; $i < 25; $i++) {
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

    private function moveOnDisk(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $src, string $dst): bool
    {
        $src = trim($src);
        $dst = trim($dst);
        if ($src === '' || $dst === '') {
            return false;
        }
        if ($src === $dst) {
            return true;
        }
        if (! $disk->exists($src)) {
            return false;
        }
        if ($disk->exists($dst)) {
            return false;
        }

        // Some filesystem adapters return false instead of throwing; verify result by existence.
        try {
            $ok = (bool) $disk->move($src, $dst);
            if ($ok && $disk->exists($dst)) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through to copy+delete.
        }

        try {
            $copied = (bool) $disk->copy($src, $dst);
            if (! $copied || ! $disk->exists($dst)) {
                return false;
            }
            $disk->delete($src);

            return true;
        } catch (\Throwable) {
            // As a last check, accept it if the destination exists.
            return $disk->exists($dst);
        }
    }
}
