<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\DTOs\StorePreorders\StorePreorderShopifyPushResult;
use App\Models\StorePreorder;
use App\Services\Shopify\Admin\Write\ShopifyProductPushBySkusService;

final class StorePreorderShopifyShelfPushService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly ShopifyProductPushBySkusService $push,
        private readonly StorePreorderCollectionReorderScheduler $reorder,
    ) {}

    /**
     * @param  list<string>  $uuids
     */
    public function pushOpen(array $uuids = []): StorePreorderShopifyPushResult
    {
        $offers = $uuids === []
            ? $this->offers->listOpen()
            : $this->offers->findByUuids($uuids);

        $skus = [];
        foreach ($offers as $offer) {
            if (! $offer instanceof StorePreorder || ! $offer->isOpen()) {
                continue;
            }
            $sku = trim((string) ($offer->product?->sku ?? $offer->plamod_sku));
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        $pushed = 0;
        $failed = 0;
        $errors = [];
        foreach ($this->push->push($skus, $this->options()) as $row) {
            if (($row['action'] ?? '') === 'error') {
                $failed++;
                $errors[] = [
                    'sku' => (string) ($row['sku'] ?? ''),
                    'message' => (string) ($row['tags'] ?? 'Push failed'),
                ];

                continue;
            }
            $pushed++;
        }

        if ($pushed > 0) {
            $this->reorder->queue();
        }

        return new StorePreorderShopifyPushResult($pushed, $failed, $errors);
    }

    private function options(): ShopifyProductPushOptionsDTO
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
}
