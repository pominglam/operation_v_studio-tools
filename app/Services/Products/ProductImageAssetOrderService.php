<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductRepository;
use App\Models\ProductExternalAsset;

final class ProductImageAssetOrderService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductExternalAssetRepository $assets,
    ) {}

    /**
     * Reorder *all* image assets for a product across sources (used by Shopify exports).
     *
     * @param  array<int, int>  $imageAssetIds
     */
    public function reorderImageAssets(string $productUuid, array $imageAssetIds): void
    {
        $product = $this->products->findByUuidOrFail($productUuid);

        $current = $this->assets->listAllForProduct((int) $product->id);
        $images = array_values(array_filter($current, static function (ProductExternalAsset $a): bool {
            if ($a->kind === 'image') {
                return true;
            }

            return str_starts_with((string) ($a->mime_type ?? ''), 'image/');
        }));

        /** @var array<int, true> $byId */
        $byId = [];
        foreach ($images as $a) {
            $byId[(int) $a->id] = true;
        }

        $desired = [];
        foreach ($imageAssetIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            if (! isset($byId[$id])) {
                continue;
            }
            if (in_array($id, $desired, true)) {
                continue;
            }
            $desired[] = $id;
        }

        // Append any images not included (keeps old ordering for leftovers).
        foreach ($images as $a) {
            $id = (int) $a->id;
            if (! in_array($id, $desired, true)) {
                $desired[] = $id;
            }
        }

        $map = [];
        $order = 0;
        foreach ($desired as $id) {
            $order++;
            $map[$id] = $order;
        }

        $this->assets->updateSortOrders($map);
    }
}
