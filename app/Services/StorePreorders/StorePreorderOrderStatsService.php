<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DTOs\StorePreorders\StorePreorderOrderStats;
use App\Models\Shopify\ShopifyOrder;
use App\Models\Shopify\ShopifyOrderLineItem;
use App\Models\StorePreorder;
use App\Services\Shopify\Admin\Orders\ShopifyOrderDemandEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StorePreorderOrderStatsService
{
    public function __construct(
        private readonly ShopifyOrderDemandEligibility $eligibility,
    ) {}

    public function totals(): StorePreorderOrderStats
    {
        $orders = ShopifyOrder::query()->whereHas('storePreorderLines');
        $this->eligibility->scopeDemandEligibleOrders($orders);

        $lines = ShopifyOrderLineItem::query()->where(function (Builder $query): void {
            $query->whereIn('product_id', StorePreorder::query()->select('product_id'))
                ->orWhereIn('sku', StorePreorder::query()->select('plamod_sku'));
        });
        $this->eligibility->scopeDemandEligibleLineItems($lines);

        return new StorePreorderOrderStats(
            (int) $orders->count(),
            (int) $lines->sum('quantity'),
        );
    }

    /**
     * @param  iterable<int, mixed>  $offers
     */
    public function attachToOffers(iterable $offers): void
    {
        $list = [];
        foreach ($offers as $offer) {
            if ($offer instanceof StorePreorder) {
                $list[] = $offer;
            }
        }
        if ($list === []) {
            return;
        }

        $lines = $this->matchingLines($list);
        foreach ($list as $offer) {
            $stats = $this->statsForOffer($offer, $lines);
            $offer->setAttribute('order_count', $stats->orderCount);
            $offer->setAttribute('unit_qty', $stats->unitQty);
        }
    }

    /**
     * @param  list<StorePreorder>  $offers
     * @return Collection<int, ShopifyOrderLineItem>
     */
    private function matchingLines(array $offers): Collection
    {
        $productIds = [];
        $skus = [];
        foreach ($offers as $offer) {
            $productId = (int) $offer->product_id;
            if ($productId > 0) {
                $productIds[] = $productId;
            }
            $sku = trim((string) $offer->plamod_sku);
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        $query = ShopifyOrderLineItem::query()->where(function (Builder $inner) use ($productIds, $skus): void {
            if ($productIds !== []) {
                $inner->whereIn('product_id', array_values(array_unique($productIds)));
            }
            if ($skus !== []) {
                $method = $productIds !== [] ? 'orWhereIn' : 'whereIn';
                $inner->{$method}('sku', array_values(array_unique($skus)));
            }
        });
        $this->eligibility->scopeDemandEligibleLineItems($query);

        return $query->get(['order_gid', 'sku', 'product_id', 'quantity']);
    }

    /**
     * @param  Collection<int, ShopifyOrderLineItem>  $lines
     */
    private function statsForOffer(StorePreorder $offer, Collection $lines): StorePreorderOrderStats
    {
        $orderGids = [];
        $unitQty = 0;
        foreach ($lines as $line) {
            if (! $this->lineMatchesOffer($line, $offer)) {
                continue;
            }
            $orderGids[(string) $line->order_gid] = true;
            $unitQty += max(0, (int) $line->quantity);
        }

        return new StorePreorderOrderStats(count($orderGids), $unitQty);
    }

    private function lineMatchesOffer(ShopifyOrderLineItem $line, StorePreorder $offer): bool
    {
        $productId = (int) $offer->product_id;
        if ($productId > 0 && (int) $line->product_id === $productId) {
            return true;
        }

        $sku = trim((string) $offer->plamod_sku);

        return $sku !== '' && trim((string) $line->sku) === $sku;
    }
}
