<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalAssetRepository;
use App\Models\ProductExternalAsset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;

final class ProductManualImageDeleteService
{
    public function __construct(
        private readonly ProductExternalAssetRepository $assets,
    ) {}

    /**
     * @throws ModelNotFoundException
     * @throws ManualUploadDeletionDeniedException
     */
    public function delete(int $assetId): void
    {
        $asset = $this->assets->findById($assetId);
        if (! $asset instanceof ProductExternalAsset) {
            throw new ModelNotFoundException('Asset not found.');
        }

        if ($asset->source !== ProductManualImageUploadService::SOURCE) {
            throw new ManualUploadDeletionDeniedException('Only manually uploaded images can be deleted.');
        }

        $storagePath = trim((string) $asset->storage_path);
        $this->assets->deleteById($assetId);

        if ($storagePath !== '') {
            Storage::disk('local')->delete($storagePath);
        }
    }
}
