<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderCollectionReorderResult
{
    public function __construct(
        public bool $attempted,
        public ?string $collectionGid,
        public int $productCount,
        public int $movesSent,
        public ?string $jobId,
        public bool $jobDone,
        public bool $jobWaitTimedOut,
        public ?string $skippedReason,
    ) {}
}
