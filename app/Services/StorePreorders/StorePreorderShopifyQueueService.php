<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\Jobs\JobBatchItemRepository;
use App\DAL\Products\ProductRepository;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Jobs\PushSelectedProductToShopifyJob;
use App\Jobs\ReorderStorePreordersCollectionJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

final class StorePreorderShopifyQueueService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly JobBatchItemRepository $batchItems,
    ) {}

    public function listingOnly(): ShopifyProductPushOptionsDTO
    {
        return new ShopifyProductPushOptionsDTO(
            info: true,
            images: false,
            quantities: true,
            price: true,
            publishStatus: true,
            salesChannels: true,
        );
    }

    public function withImages(): ShopifyProductPushOptionsDTO
    {
        return new ShopifyProductPushOptionsDTO(
            info: true,
            images: true,
            quantities: true,
            price: true,
            publishStatus: true,
            salesChannels: true,
        );
    }

    public function imagesOnly(): ShopifyProductPushOptionsDTO
    {
        return new ShopifyProductPushOptionsDTO(
            info: false,
            images: true,
            quantities: false,
            price: false,
            publishStatus: false,
            salesChannels: false,
        );
    }

    /**
     * @param  list<string>  $productUuids
     */
    public function queue(array $productUuids, ShopifyProductPushOptionsDTO $options): int
    {
        $productUuids = array_values(array_unique(array_filter(
            array_map(static fn (string $uuid): string => trim($uuid), $productUuids),
            static fn (string $uuid): bool => $uuid !== '',
        )));
        if ($productUuids === [] || ! $options->hasAny()) {
            return 0;
        }

        $existing = $this->products->findByUuids($productUuids)->keyBy('uuid');
        $jobs = [];
        $items = [];
        $optionsLine = '[job] store_preorder_push='.implode(',', $this->enabledKeys($options));

        foreach ($productUuids as $uuid) {
            $product = $existing->get($uuid);
            if ($product === null) {
                continue;
            }

            $jobs[] = new PushSelectedProductToShopifyJob((string) Str::uuid(), $uuid, $options->toArray());
            $items[] = [
                'product_uuid' => $uuid,
                'sku' => is_string($product->sku ?? null) ? (string) $product->sku : null,
                'vendor' => is_string($product->vendor ?? null) ? (string) $product->vendor : null,
                'debug_log' => $optionsLine,
            ];
        }

        if ($jobs === []) {
            return 0;
        }

        $batch = Bus::batch($jobs)
            ->name('store_preorder_shopify_push')
            ->allowFailures()
            ->finally(static function (): void {
                ReorderStorePreordersCollectionJob::dispatch();
            })
            ->dispatch();
        $this->batchItems->insertQueued($batch->id, $items);

        return count($items);
    }

    /**
     * @return list<string>
     */
    private function enabledKeys(ShopifyProductPushOptionsDTO $options): array
    {
        $keys = [];
        foreach ($options->toArray() as $key => $enabled) {
            if ($enabled) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
