<?php

declare(strict_types=1);

namespace App\DAL\Jobs;

interface JobBatchItemRepository
{
    /**
     * @param  array<int, array{
     *   product_uuid:string,
     *   sku:string|null,
     *   vendor:string|null
     * }>  $products
     */
    public function insertQueued(string $batchId, array $products): void;

    public function markRunning(string $batchId, string $productUuid, ?string $syncUuid = null): void;

    public function markSucceeded(string $batchId, string $productUuid): void;

    public function markFailed(string $batchId, string $productUuid, string $error): void;

    public function markSkipped(string $batchId, string $productUuid, string $reason): void;

    /**
     * @return array{
     *   counts: array{queued:int,running:int,succeeded:int,failed:int,skipped:int},
     *   running: array<int, array<string, mixed>>,
     *   queued: array<int, array<string, mixed>>,
     *   done: array<int, array<string, mixed>>
     * }
     */
    public function getSummary(string $batchId, int $limitPerSection = 25): array;

    /**
     * Best-effort backfill of queued/running/failed items for an existing batch by scanning the database queue tables.
     * Safe to call repeatedly.
     */
    public function backfillFromQueueTables(string $batchId): void;
}


