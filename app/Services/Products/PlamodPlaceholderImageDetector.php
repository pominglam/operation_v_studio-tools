<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\ProductExternalAsset;
use Illuminate\Support\Facades\Storage;

final class PlamodPlaceholderImageDetector
{
    /**
     * Plamod hub/PDP "No image" graphics that were previously stored as product photos.
     *
     * @var list<string>
     */
    public const array BANNED_SHA256 = [
        '01cb3806218cc66762de706389d99a3e16f267d56f2822358cbedd6584ceb319',
        'd4a5e7c2cc90c3d8977dac23415e4976e670f439f1024c6960adac0f231c7fdc',
    ];

    public function isPlaceholderChecksum(?string $sha256): bool
    {
        $sha256 = strtolower(trim((string) $sha256));

        return $sha256 !== '' && in_array($sha256, self::BANNED_SHA256, true);
    }

    public function isPlaceholderBytes(string $bytes): bool
    {
        return $bytes !== '' && $this->isPlaceholderChecksum(hash('sha256', $bytes));
    }

    public function isPlaceholderPath(string $storagePath): bool
    {
        $storagePath = trim($storagePath);
        if ($storagePath === '' || ! Storage::disk('local')->exists($storagePath)) {
            return false;
        }

        $abs = Storage::disk('local')->path($storagePath);

        return is_file($abs) && $this->isPlaceholderChecksum(hash_file('sha256', $abs) ?: null);
    }

    public function isPlaceholderAsset(ProductExternalAsset $asset): bool
    {
        if ($this->isPlaceholderChecksum($asset->checksum_sha256)) {
            return true;
        }

        return $this->isPlaceholderPath((string) $asset->storage_path);
    }
}
