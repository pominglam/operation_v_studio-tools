<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderBulkUpdateResult
{
    public function __construct(
        public int $updated,
        public int $skipped,
    ) {}
}
