<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final class ProductsBulkRenameAssetsResultDTO
{
    public function __construct(
        public int $queued,
        public string $batchId,
    ) {}
}
