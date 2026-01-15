<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final readonly class ProductImportRowDTO
{
    public function __construct(
        public string $sku,
        public ?string $barcode,
        public string $description,
        public ?string $type,
        public ?string $vendor,
        public ?string $latestUnitCost,
        public ?int $orderQty,
        public ?int $filledQty,
        public ?string $extended,
    ) {}
}
