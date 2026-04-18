<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final readonly class MissingInfoSyncResultDTO
{
    public function __construct(
        public int $queued,
        public bool $dryRun,
        public ?string $batchId,
    ) {}
}
