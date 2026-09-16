<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\DTOs\Products\ProductSellingPriceUpsertContext;
use App\DTOs\StorePreorders\StorePreorderBulkUpdateResult;
use App\Models\StorePreorder;
use App\Services\Products\ProductSellingPriceService;
use App\Services\StorePreorders\Exceptions\StorePreorderUpdateException;
use App\Support\Pricing\OpvStandardCatalogPrice;
use App\Support\StorePreorders\OpvCatalogPricingSettings;
use Illuminate\Support\Facades\DB;

final class StorePreorderBulkUpdateService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly ProductSellingPriceService $sellingPrices,
        private readonly StorePreorderShopifyOfferSyncService $shopify,
        private readonly StorePreorderCollectionReorderScheduler $reorder,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @param  array{cap_qty?: int|null, deposit_percent?: string|int|float, selling_price?: string|int|float, window_ends_on?: string}  $changes
     */
    public function update(array $uuids, array $changes): StorePreorderBulkUpdateResult
    {
        $attributes = $this->attributesFromChanges($changes);

        $synced = [];
        $result = DB::transaction(function () use ($uuids, $attributes, &$synced): StorePreorderBulkUpdateResult {
            $updated = 0;
            $skipped = 0;
            foreach ($this->offers->findByUuids($uuids) as $offer) {
                if (! $offer->isOpen()) {
                    $skipped++;

                    continue;
                }
                $synced[] = $this->apply($offer, $attributes);
                $updated++;
            }

            return new StorePreorderBulkUpdateResult($updated, $skipped);
        });
        foreach ($synced as $offer) {
            $this->shopify->syncIfMirrored($offer);
        }
        if ($result->updated > 0 && array_key_exists('window_ends_on', $changes)) {
            $this->reorder->queue();
        }

        return $result;
    }

    /**
     * @param  array{cap_qty?: int|null, deposit_percent?: string|int|float, selling_price?: string|int|float, window_ends_on?: string}  $changes
     * @return array<string, mixed>
     */
    private function attributesFromChanges(array $changes): array
    {
        $attributes = [];
        if (array_key_exists('cap_qty', $changes)) {
            $attributes['cap_qty'] = $changes['cap_qty'];
        }
        if (array_key_exists('deposit_percent', $changes)) {
            $deposit = OpvCatalogPricingSettings::normalizeDeposit($changes['deposit_percent']);
            if ($deposit === null) {
                throw new StorePreorderUpdateException('Deposit must be between 1 and 100.');
            }
            $attributes['deposit_percent'] = $deposit;
        }
        if (array_key_exists('selling_price', $changes)) {
            $attributes['selling_price_cad'] = $this->snappedSell($changes['selling_price']);
        }
        if (array_key_exists('window_ends_on', $changes)) {
            $attributes['window_ends_on'] = $changes['window_ends_on'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apply(StorePreorder $offer, array $attributes): StorePreorder
    {
        $updated = $this->offers->update($offer, $attributes);
        $updated->loadMissing('product');
        $price = $attributes['selling_price_cad'] ?? null;
        if (! is_string($price) || $price === '') {
            return $updated;
        }
        $productUuid = trim((string) ($updated->product?->uuid ?? ''));
        if ($productUuid === '') {
            return $updated;
        }
        $this->sellingPrices->upsertForProductUuid(
            $productUuid,
            $price,
            'CAD',
            new ProductSellingPriceUpsertContext('store_preorder'),
        );

        return $updated;
    }

    private function snappedSell(mixed $value): string
    {
        $normalized = OpvCatalogPricingSettings::normalizeMoney($value);
        $snapped = $normalized !== null ? OpvStandardCatalogPrice::fromEnteredPrice($normalized) : null;
        if ($snapped === null) {
            throw new StorePreorderUpdateException('Sell $ must be between 0.01 and 99999.99.');
        }

        return $snapped;
    }
}
