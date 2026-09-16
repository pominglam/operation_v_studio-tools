<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderOrderStats
{
    public function __construct(
        public int $orderCount,
        public int $unitQty,
    ) {}
}
