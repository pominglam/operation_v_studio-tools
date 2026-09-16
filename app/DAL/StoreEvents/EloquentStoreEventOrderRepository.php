<?php

declare(strict_types=1);

namespace App\DAL\StoreEvents;

use App\Models\Shopify\ShopifyOrder;
use App\Services\Shopify\Admin\Orders\ShopifyOrderDemandEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class EloquentStoreEventOrderRepository implements StoreEventOrderRepository
{
    public function __construct(
        private readonly ShopifyOrderDemandEligibility $eligibility,
    ) {}

    public function eligibleBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $query = ShopifyOrder::query();
        $this->eligibility->scopeDemandEligibleOrders($query);

        return $query
            ->with(['lineItems.product:id,sku,description', 'storeEvent:id,uuid,name'])
            ->withCount('lineItems')
            ->where('ordered_at_shop_tz', '>=', $start)
            ->where('ordered_at_shop_tz', '<', $end)
            ->orderBy('ordered_at_shop_tz')
            ->orderBy('id')
            ->get();
    }

    public function assign(array $orderIds, ?int $eventId): int
    {
        if ($orderIds === []) {
            return 0;
        }

        return ShopifyOrder::query()
            ->whereIn('id', $orderIds)
            ->update(['store_event_id' => $eventId]);
    }

    public function unassignFromEvent(array $orderIds, int $eventId): int
    {
        if ($orderIds === []) {
            return 0;
        }

        return ShopifyOrder::query()
            ->whereIn('id', $orderIds)
            ->where('store_event_id', $eventId)
            ->update(['store_event_id' => null]);
    }
}
