<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Models\StorePreorder;
use App\Services\Shopify\Admin\Write\ShopifyProductMirrorBySkuResolver;
use App\Services\Shopify\Admin\Write\ShopifyProductPushBySkusService;
use App\Services\StorePreorders\Exceptions\StorePreorderUpdateException;

final class StorePreorderShopifyOfferSyncService
{
    public function __construct(
        private readonly ShopifyProductMirrorBySkuResolver $mirrors,
        private readonly ShopifyProductPushBySkusService $push,
    ) {}

    public function syncIfMirrored(StorePreorder $offer): void
    {
        $sku = trim((string) ($offer->product?->sku ?? $offer->plamod_sku));
        if ($sku === '' || $this->mirrors->resolve($sku) === null) {
            return;
        }

        $rows = $this->push->push([$sku], new ShopifyProductPushOptionsDTO(
            info: true,
            images: false,
            quantities: true,
            price: true,
            publishStatus: false,
            salesChannels: false,
        ));
        $row = $rows[0] ?? null;
        if ($row === null || ($row['action'] ?? '') === 'error') {
            throw new StorePreorderUpdateException(
                'Shopify did not update: '.($row['tags'] ?? 'unknown error'),
            );
        }
    }
}
