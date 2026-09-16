<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\Products\ProductRepository;
use App\DAL\StorePreorders\StorePreorderRepository;
use App\DTOs\StorePreorders\StorePreorderBulkDeleteResult;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Product;
use App\Models\StorePreorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class StorePreorderDeleteService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly ProductRepository $products,
        private readonly StorePreorderShopifyShelfSyncService $shopifyShelf,
        private readonly StorePreorderCollectionReorderScheduler $reorder,
    ) {}

    /** @return array{product_deleted: bool} */
    public function delete(string $uuid): array
    {
        $sku = '';
        $result = DB::transaction(function () use ($uuid, &$sku): array {
            $offer = $this->offers->findByUuidOrFail($uuid);
            $sku = trim((string) $offer->plamod_sku);

            return $this->deleteOffer($offer);
        });
        $this->syncShopifySku($sku);
        $this->reorder->queue();

        return $result;
    }

    /**
     * @param  list<string>  $uuids
     */
    public function deleteMany(array $uuids): StorePreorderBulkDeleteResult
    {
        $skus = [];
        $result = DB::transaction(function () use ($uuids, &$skus): StorePreorderBulkDeleteResult {
            $deleted = 0;
            $productsDeleted = 0;
            foreach ($this->offers->findByUuids($uuids) as $offer) {
                $skus[] = trim((string) $offer->plamod_sku);
                $row = $this->deleteOffer($offer);
                $deleted++;
                if ($row['product_deleted']) {
                    $productsDeleted++;
                }
            }

            return new StorePreorderBulkDeleteResult($deleted, $productsDeleted);
        });
        foreach (array_values(array_unique(array_filter($skus))) as $sku) {
            $this->syncShopifySku($sku);
        }
        if ($result->deleted > 0) {
            $this->reorder->queue();
        }

        return $result;
    }

    private function syncShopifySku(string $sku): void
    {
        if ($sku === '') {
            return;
        }

        try {
            $this->shopifyShelf->syncSku($sku);
        } catch (ShopifyGraphQlException $e) {
            Log::channel('shopify')->error('shopify.store_preorder.shelf_sync.failed', [
                'sku' => $sku,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** @return array{product_deleted: bool} */
    private function deleteOffer(StorePreorder $offer): array
    {
        $product = $offer->product;
        $this->offers->delete($offer);

        return [
            'product_deleted' => $this->deleteUnusedProduct($product),
        ];
    }

    private function deleteUnusedProduct(?Product $product): bool
    {
        if ($product === null) {
            return false;
        }

        $uuid = trim((string) $product->uuid);
        if ($uuid === '') {
            return false;
        }

        try {
            return $this->products->deleteByUuids([$uuid]) > 0;
        } catch (ConflictHttpException) {
            return false;
        }
    }
}
