<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductRepository;
use App\Models\PlamodPreorder;
use App\Services\Plamod\PlamodPreorderImageService;
use App\Services\Products\PlamodPlaceholderImageDetector;
use Illuminate\Support\Facades\Storage;

final class StorePreorderPlamodPickListImageAttachService
{
    public const string SOURCE = 'plamod_preorder';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductExternalAssetRepository $assets,
        private readonly PlamodPreorderImageService $preorderImages,
        private readonly PlamodPlaceholderImageDetector $placeholders,
        private readonly StorePreorderRealShopifyImagePolicy $realImages,
    ) {}

    public function attachIfMissing(string $productUuid): bool
    {
        $productUuid = trim($productUuid);
        if ($productUuid === '' || $this->productHasShopifyImages($productUuid)) {
            return false;
        }

        $product = $this->products->findByUuidOrFail($productUuid);
        $sku = trim((string) $product->sku);
        if ($sku === '') {
            return false;
        }

        $row = $this->ensureLocalPickListImage($sku);
        if ($row === null) {
            return false;
        }

        $disk = Storage::disk('local');
        $sourcePath = trim((string) $row->image_storage_path);
        if ($sourcePath === '' || ! $disk->exists($sourcePath)) {
            return false;
        }

        $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        $ext = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) ? ($ext === 'jpeg' ? 'jpg' : $ext) : 'jpg';
        $filename = $sku.'-preorder.'.$ext;
        $destPath = 'plamod/preorder-product-images/'.$productUuid.'/'.$filename;
        if ($this->placeholders->isPlaceholderPath($sourcePath)) {
            return false;
        }

        $disk->put($destPath, $disk->get($sourcePath));
        if ($this->placeholders->isPlaceholderPath($destPath)) {
            $disk->delete($destPath);

            return false;
        }

        $abs = $disk->path($destPath);
        $this->assets->createForProduct((int) $product->id, self::SOURCE, [[
            'kind' => 'image',
            'storage_path' => $destPath,
            'filename' => $filename,
            'mime_type' => $this->mimeForExtension($ext),
            'size_bytes' => is_file($abs) ? (int) filesize($abs) : null,
            'origin_url' => is_string($row->source_image_url) && trim($row->source_image_url) !== ''
                ? trim($row->source_image_url)
                : null,
            'checksum_sha256' => is_file($abs) ? hash_file('sha256', $abs) : null,
            'sort_order' => 1,
            'shopify_enabled' => true,
        ]]);

        return $this->realImages->hasRealImages($productUuid);
    }

    private function productHasShopifyImages(string $productUuid): bool
    {
        return $this->realImages->hasRealImages($productUuid);
    }

    private function ensureLocalPickListImage(string $sku): ?PlamodPreorder
    {
        $row = PlamodPreorder::query()->where('sku', '=', $sku)->first();
        if ($row === null) {
            return null;
        }

        $path = is_string($row->image_storage_path) ? trim($row->image_storage_path) : '';
        if ($path !== '' && Storage::disk('local')->exists($path) && ! $this->placeholders->isPlaceholderPath($path)) {
            return $row;
        }

        if (! $this->preorderImages->downloadForSku($sku)) {
            return null;
        }

        $row->refresh();

        return $row;
    }

    private function mimeForExtension(string $ext): string
    {
        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
