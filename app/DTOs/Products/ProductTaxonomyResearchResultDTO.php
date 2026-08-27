<?php

declare(strict_types=1);

namespace App\DTOs\Products;

use App\Models\ProductTaxonomyResearchRun;

final readonly class ProductTaxonomyResearchResultDTO
{
    public function __construct(
        public ProductTaxonomyResearchRun $run,
        public int $processed,
        public int $proposed,
        public int $failed,
    ) {}
}
