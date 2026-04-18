<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\DAL\Jobs\JobBatchItemRepository;

final class JobBatchItemQueryService
{
    public function __construct(private readonly JobBatchItemRepository $repo) {}

    /**
     * @return array{
     *   counts: array{queued:int,running:int,succeeded:int,failed:int,skipped:int},
     *   running: array<int, array<string, mixed>>,
     *   queued: array<int, array<string, mixed>>,
     *   done: array<int, array<string, mixed>>
     * }
     */
    public function getSummary(string $batchId, int $limitPerSection = 25): array
    {
        $this->repo->backfillFromQueueTables($batchId);

        return $this->repo->getSummary($batchId, $limitPerSection);
    }
}
