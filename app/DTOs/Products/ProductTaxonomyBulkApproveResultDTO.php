<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final readonly class ProductTaxonomyBulkApproveResultDTO
{
    public function __construct(
        public int $approved,
        public int $skipped,
        public int $failed,
    ) {}
}
