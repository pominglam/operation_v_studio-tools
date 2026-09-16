<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final readonly class ModelKitSeriesProposal
{
    public function __construct(
        public string $erpSeries,
        public string $tagSlug,
        public string $ruleId,
        public string $confidence,
        public string $evidence,
    ) {}
}
