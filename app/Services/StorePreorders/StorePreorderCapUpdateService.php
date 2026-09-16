<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\Models\StorePreorder;
use App\Services\StorePreorders\Exceptions\StorePreorderUpdateException;

final class StorePreorderCapUpdateService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly StorePreorderShopifyOfferSyncService $shopify,
    ) {}

    public function update(string $uuid, ?int $capQty): StorePreorder
    {
        $offer = $this->offers->findByUuidOrFail($uuid);
        if (! $offer->isOpen()) {
            throw new StorePreorderUpdateException('Cap can only be changed on an open store preorder.');
        }

        $updated = $this->offers->update($offer, [
            'cap_qty' => $capQty,
        ]);
        $updated->loadMissing('product');
        $this->shopify->syncIfMirrored($updated);

        return $updated;
    }
}
