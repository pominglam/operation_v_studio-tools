<?php

declare(strict_types=1);

namespace App\Support\Customers;

use Carbon\CarbonInterface;

final readonly class ShopifyOrderIdentityRow
{
    public function __construct(
        public int $id,
        public string $gid,
        public ?string $legacyNumericId,
        public ?string $name,
        public ?string $customerGid,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public ?string $subtotalShopAmount,
        public ?CarbonInterface $orderedAt,
        public ?string $sourceName,
        public ?string $channelName,
        public bool $eligible,
    ) {}
}
