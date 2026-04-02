<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\DAL\Products\ProductExternalAssetRepository;
use App\Models\ProductExternalAsset;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

final class ShopifyImageServeService
{
    public function __construct(
        private readonly ProductExternalAssetRepository $assets,
    ) {}

    /**
     * Resolve a publicly-servable image asset to an existing storage path.
     *
     * If the DB storage_path is missing (e.g. a prior rename failed to move the file),
     * this will attempt a best-effort repair by locating the file in the same directory
     * using checksum matching, then persisting the repaired storage_path.
     *
     * @return array{asset: ProductExternalAsset, storage_path: string}|null
     */
    public function resolve(int $id, ?string $requestedFilename = null): ?array
    {
        $asset = $this->assets->findById($id);
        if (! $asset instanceof ProductExternalAsset) {
            return null;
        }

        // Only serve images (defense-in-depth; URLs must be safe to expose publicly).
        $isImage = $asset->kind === 'image' || str_starts_with((string) ($asset->mime_type ?? ''), 'image/');
        if (! $isImage) {
            return null;
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        $storagePath = $this->resolveStoragePath($disk, $asset, $requestedFilename);
        if ($storagePath === null) {
            return null;
        }

        // Best-effort self-heal for restrictive perms (e.g. 0700 dirs from crawler/worker umask).
        $this->repairStoragePathPermissions($disk, $storagePath);

        if ($storagePath !== (string) $asset->storage_path) {
            $asset->storage_path = $storagePath;
            $asset->save();
        }

        return [
            'asset' => $asset,
            'storage_path' => $storagePath,
        ];
    }

    public function repairStoragePath(string $storagePath): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');
        $this->repairStoragePathPermissions($disk, $storagePath);
    }

    private function resolveStoragePath(FilesystemAdapter $disk, ProductExternalAsset $asset, ?string $requestedFilename): ?string
    {
        $current = is_string($asset->storage_path) ? trim($asset->storage_path) : '';
        if ($current !== '' && $this->safeDiskExists($disk, $current)) {
            return $current;
        }

        // If a filename was included in the URL, try it in the same directory.
        $dir = $current !== '' ? trim((string) dirname($current), '.') : '';
        $decoded = is_string($requestedFilename) ? rawurldecode($requestedFilename) : '';
        $decoded = trim($decoded);
        if ($decoded !== '') {
            $candidate = ($dir !== '' ? ($dir.'/') : '').basename($decoded);
            if ($this->safeDiskExists($disk, $candidate)) {
                return $candidate;
            }
        }

        // Best-effort repair: locate by checksum within the same directory.
        $sha = is_string($asset->checksum_sha256) ? trim($asset->checksum_sha256) : '';
        if ($sha === '' || $dir === '') {
            return null;
        }

        $files = $this->safeDiskFiles($disk, $dir);
        if ($files === []) {
            return null;
        }

        foreach ($files as $path) {
            $path = is_string($path) ? trim($path) : '';
            if ($path === '') {
                continue;
            }
            if (! $this->safeDiskExists($disk, $path)) {
                continue;
            }

            // Skip obvious non-images by extension to reduce IO.
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if ($ext !== '' && ! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                continue;
            }

            $abs = $disk->path($path);
            if (! is_string($abs) || trim($abs) === '') {
                continue;
            }
            if (! is_file($abs)) {
                continue;
            }

            try {
                if (hash_file('sha256', $abs) === $sha) {
                    return $path;
                }
            } catch (\Throwable) {
                // ignore and keep searching
            }
        }

        return null;
    }

    private function safeDiskExists(FilesystemAdapter $disk, string $path): bool
    {
        try {
            return $disk->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    private function safeDiskFiles(FilesystemAdapter $disk, string $dir): array
    {
        try {
            /** @var array<int, string> $out */
            $out = $disk->files($dir);

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function repairStoragePathPermissions(FilesystemAdapter $disk, string $storagePath): void
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
