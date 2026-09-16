<?php

declare(strict_types=1);

namespace App\Support\Shopify\Admin\Orders;

final readonly class ShopifyOrderIndexFilters
{
    public function __construct(
        public string $fromDate,
        public string $untilDate,
        public ?string $search,
        public ?string $channel,
        public string $status,
        public string $preorder,
        public string $sortBy,
        public string $sortDir,
        public int $perPage,
    ) {}
}
