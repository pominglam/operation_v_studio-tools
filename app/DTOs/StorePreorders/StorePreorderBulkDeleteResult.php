<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderBulkDeleteResult
{
    public function __construct(
        public int $deleted,
        public int $productsDeleted,
    ) {}
}
