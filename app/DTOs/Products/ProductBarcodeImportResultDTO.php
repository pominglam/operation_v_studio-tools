<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final class ProductBarcodeImportResultDTO
{
    /**
     * @param  array<int, array{vendor:string, sku:string, old:string|null, new:string}>  $updated
     * @param  array<int, array{vendor:string, sku:string, reason:string}>  $skipped
     * @param  array<int, array{vendor:string, sku:string, reason:string}>  $missing
     * @param  array<int, array{vendor:string, sku:string, reason:string}>  $ambiguous
     */
    public function __construct(
        public readonly int $rowsRead,
        public readonly int $matched,
        public readonly int $updatedCount,
        public readonly int $skippedCount,
        public readonly int $missingCount,
        public readonly int $ambiguousCount,
        public readonly array $updated,
        public readonly array $skipped,
        public readonly array $missing,
        public readonly array $ambiguous,
    ) {}
}
