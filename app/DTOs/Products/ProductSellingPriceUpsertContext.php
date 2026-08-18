<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final readonly class ProductSellingPriceUpsertContext
{
    public function __construct(
        public string $source,
        public ?int $purchaseOrderId = null,
    ) {}
}
