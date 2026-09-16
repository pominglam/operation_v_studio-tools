<?php

declare(strict_types=1);

namespace App\DTOs\StoreEvents;

final readonly class StoreEventOrderAssignResult
{
    public function __construct(
        public int $updated,
    ) {}
}
