<?php

declare(strict_types=1);

namespace App\Support\Customers;

final readonly class CustomerRetentionIndexFilters
{
    public function __construct(
        public ?string $search,
        public ?string $frequency,
        public ?string $rfmGroup,
        public ?string $churn,
        public ?string $cadence,
        public string $sortBy,
        public string $sortDir,
        public int $page,
        public int $perPage,
    ) {}
}
