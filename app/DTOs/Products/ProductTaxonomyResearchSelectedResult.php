<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final readonly class ProductTaxonomyResearchSelectedResult
{
    public function __construct(
        public int $researched,
        public int $skipped,
        public int $failed,
    ) {}
}
