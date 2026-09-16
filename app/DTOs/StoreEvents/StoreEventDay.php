<?php

declare(strict_types=1);

namespace App\DTOs\StoreEvents;

use App\Models\Shopify\ShopifyOrder;

final readonly class StoreEventDay
{
    /**
     * @param  list<ShopifyOrder>  $orders
     */
    public function __construct(
        public string $date,
        public int $eventOrderCount,
        public int $otherOrderCount,
        public string $eventSubtotal,
        public string $otherSubtotal,
        public array $orders,
    ) {}
}
