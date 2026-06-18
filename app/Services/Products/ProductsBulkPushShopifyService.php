<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Jobs\JobBatchItemRepository;
use App\DAL\Products\ProductRepository;
use App\DTOs\Products\ProductsBulkPushShopifyResultDTO;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Jobs\PushSelectedProductToShopifyJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

final class ProductsBulkPushShopifyService
{
    public function __construct(
        private readonly ProductsBulkPushShopifyPreviewService $preview,
        private readonly ProductRepository $products,
        private readonly JobBatchItemRepository $batchItems,
    ) {}

    /**
     * @param  array<int, string>  $productUuids
     */
    public function pushSelected(array $productUuids, ShopifyProductPushOptionsDTO $options): ProductsBulkPushShopifyResultDTO
    {
        if (! $options->hasAny()) {
            return new ProductsBulkPushShopifyResultDTO(0, '');
        }

        $previewData = $this->preview->preview($productUuids, $options);
        $uuids = $previewData['product_uuids'];
        if ($uuids === []) {
            return new ProductsBulkPushShopifyResultDTO(0, '');
        }

        $enabledKeys = [];
        foreach ($options->toArray() as $key => $enabled) {
            if ($enabled) {
                $enabledKeys[] = $key;
            }
        }
        $optionsLine = '[job] push_options='.($enabledKeys !== [] ? implode(',', $enabledKeys) : 'none');

        $jobs = [];
        $itemProducts = [];
        $existing = $this->products->findByUuids($uuids)->keyBy('uuid');

        foreach ($uuids as $uuid) {
            $product = $existing->get($uuid);
            if ($product === null) {
                continue;
            }

            $jobs[] = new PushSelectedProductToShopifyJob(
                (string) Str::uuid(),
                (string) $uuid,
                $options->toArray(),
            );

            $itemProducts[] = [
                'product_uuid' => (string) $uuid,
                'sku' => is_string($product->sku ?? null) ? (string) $product->sku : null,
                'vendor' => is_string($product->vendor ?? null) ? (string) $product->vendor : null,
                'debug_log' => $optionsLine,
            ];
        }

        if ($jobs === []) {
            return new ProductsBulkPushShopifyResultDTO(0, '');
        }

        $batch = Bus::batch($jobs)
            ->name('push_selected_products_shopify')
            ->allowFailures()
            ->dispatch();

        $this->batchItems->insertQueued($batch->id, $itemProducts);

        return new ProductsBulkPushShopifyResultDTO(
            queued: count($itemProducts),
            batchId: $batch->id,
        );
    }
}
