<?php

declare(strict_types=1);

namespace App\Support\Products;

final readonly class ProductSavedViewWriteData
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $visibleColumns
     */
    public function __construct(
        public string $name,
        public array $snapshot,
        public array $visibleColumns,
    ) {}
}
