<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final class StediEnglishNameBackfillResultDTO
{
    /**
     * @param  array<int, array{sku:string, old:string, new:string}>  $updated
     * @param  array<int, array{sku:string, reason:string}>  $skipped
     * @param  array<int, array{sku:string, reason:string}>  $missing
     */
    public function __construct(
        public readonly int $rowsRead,
        public readonly int $matched,
        public readonly int $updatedCount,
        public readonly int $skippedCount,
        public readonly int $missingCount,
        public readonly array $updated,
        public readonly array $skipped,
        public readonly array $missing,
    ) {}
}

