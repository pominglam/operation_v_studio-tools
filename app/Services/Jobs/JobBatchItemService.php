<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\DAL\Jobs\JobBatchItemRepository;

final class JobBatchItemService
{
    public function __construct(private readonly JobBatchItemRepository $repo) {}

    public function markRunning(string $batchId, string $productUuid, ?string $syncUuid = null): void
    {
        $this->repo->markRunning($batchId, $productUuid, $syncUuid);
    }

    public function markSucceeded(string $batchId, string $productUuid): void
    {
        $this->repo->markSucceeded($batchId, $productUuid);
    }

    public function markFailed(string $batchId, string $productUuid, string $error): void
    {
        $this->repo->markFailed($batchId, $productUuid, $error);
    }

    public function markSkipped(string $batchId, string $productUuid, string $reason): void
    {
        $this->repo->markSkipped($batchId, $productUuid, $reason);
    }

    public function appendDebugLog(string $batchId, string $productUuid, string $line): void
    {
        $this->repo->appendDebugLog($batchId, $productUuid, $line);
    }
}
