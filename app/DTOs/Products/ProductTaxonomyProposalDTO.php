<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final readonly class ProductTaxonomyProposalDTO
{
    /**
     * @param array{
     *     department: string|null,
     *     manufacturer: string|null,
     *     franchise: string|null,
     *     product_line: string|null,
     *     subline: string|null,
     *     grade: string|null,
     *     series: string|null,
     *     scale: string|null
     * } $values
     * @param array<string, array{
     *     value: string|null,
     *     source_url: string|null,
     *     source_label: string,
     *     confidence: int,
     *     notes: string|null
     * }> $evidence
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public array $values,
        public array $evidence,
        public int $overallConfidence,
        public array $notes = [],
    ) {}
}
