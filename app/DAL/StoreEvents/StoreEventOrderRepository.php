<?php

declare(strict_types=1);

namespace App\DAL\StoreEvents;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

interface StoreEventOrderRepository
{
    /**
     * @return Collection<int, \App\Models\Shopify\ShopifyOrder>
     */
    public function eligibleBetween(CarbonImmutable $start, CarbonImmutable $end): Collection;

    /**
     * @param  list<int>  $orderIds
     */
    public function assign(array $orderIds, ?int $eventId): int;

    /**
     * @param  list<int>  $orderIds
     */
    public function unassignFromEvent(array $orderIds, int $eventId): int;
}
