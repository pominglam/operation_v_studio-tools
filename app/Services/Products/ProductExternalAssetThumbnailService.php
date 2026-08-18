<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTOs\Products\ProductExternalAssetServePath;
use App\Models\ProductExternalAsset;
use GdImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

final class ProductExternalAssetThumbnailService
{
    public const MAX_WIDTH = 320;

    public const JPEG_QUALITY = 82;

    public function resolveServePath(ProductExternalAsset $asset): ?ProductExternalAssetServePath
    {
        if ($asset->kind !== 'image') {
            return null;
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');
        $storagePath = trim((string) $asset->storage_path);
        if ($storagePath === '' || ! $disk->exists($storagePath)) {
            return null;
        }

        $sourceAbsolute = $disk->path($storagePath);
        if (! is_string($sourceAbsolute) || $sourceAbsolute === '') {
            return null;
        }

        if (! extension_loaded('gd')) {
            return $this->servePathFromAbsolute($sourceAbsolute, $asset);
        }

        $thumbRelative = $this->thumbnailRelativePath($asset->id);
        if ($disk->exists($thumbRelative) && $this->isThumbFresh($disk, $storagePath, $thumbRelative)) {
            $thumbAbsolute = $disk->path($thumbRelative);

            return new ProductExternalAssetServePath(
                absolutePath: is_string($thumbAbsolute) ? $thumbAbsolute : $sourceAbsolute,
                mimeType: 'image/jpeg',
                filename: 'thumb-'.$asset->filename,
            );
        }

        if ($this->generateThumbnail($sourceAbsolute, $thumbRelative, $disk)) {
            $thumbAbsolute = $disk->path($thumbRelative);
            if (is_string($thumbAbsolute) && $thumbAbsolute !== '' && $disk->exists($thumbRelative)) {
                return new ProductExternalAssetServePath(
                    absolutePath: $thumbAbsolute,
                    mimeType: 'image/jpeg',
                    filename: 'thumb-'.$asset->filename,
                );
            }
        }

        return $this->servePathFromAbsolute($sourceAbsolute, $asset);
    }

    public function thumbnailRelativePath(int $assetId): string
    {
        return 'product-external-assets/thumbs/'.$assetId.'.jpg';
    }

    public function deleteThumbnail(int $assetId): void
    {
        Storage::disk('local')->delete($this->thumbnailRelativePath($assetId));
    }

    private function servePathFromAbsolute(string $absolutePath, ProductExternalAsset $asset): ProductExternalAssetServePath
    {
        $mime = is_string($asset->mime_type) && $asset->mime_type !== ''
            ? $asset->mime_type
            : 'application/octet-stream';

        return new ProductExternalAssetServePath(
            absolutePath: $absolutePath,
            mimeType: $mime,
            filename: (string) $asset->filename,
        );
    }

    private function isThumbFresh(FilesystemAdapter $disk, string $sourceRelative, string $thumbRelative): bool
    {
        try {
            return $disk->lastModified($thumbRelative) >= $disk->lastModified($sourceRelative);
        } catch (\Throwable) {
            return false;
        }
    }

    private function generateThumbnail(string $sourceAbsolute, string $thumbRelative, FilesystemAdapter $disk): bool
    {
        $image = $this->loadImage($sourceAbsolute);
        if (! $image instanceof GdImage) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);

            return false;
        }

        $targetWidth = min(self::MAX_WIDTH, $width);
        $targetHeight = (int) max(1, round($height * ($targetWidth / $width)));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            imagedestroy($image);

            return false;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        if ($white !== false) {
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        }

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        ob_start();
        $written = imagejpeg($canvas, null, self::JPEG_QUALITY);
        /** @var string $bytes */
        $bytes = ob_get_clean();
        imagedestroy($canvas);

        if (! $written || $bytes === '') {
            return false;
        }

        $saved = $disk->put($thumbRelative, $bytes);
        if ($saved) {
            $absolute = $disk->path($thumbRelative);
            if (is_string($absolute) && is_file($absolute)) {
                @chmod($absolute, 0644);
            }
        }

        return $saved;
    }

    private function loadImage(string $absolutePath): GdImage|false
    {
        $bytes = @file_get_contents($absolutePath);
        if (! is_string($bytes) || $bytes === '') {
            return false;
        }

        $image = @imagecreatefromstring($bytes);

        return $image instanceof GdImage ? $image : false;
    }
}
