<?php

declare(strict_types=1);

namespace App\DAL\Jobs;

use App\Jobs\SyncPlamodAssetsJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentJobBatchItemRepository implements JobBatchItemRepository
{
    public function insertQueued(string $batchId, array $products): void
    {
        if ($products === []) return;

        $now = Carbon::now();

        $rows = array_map(static function (array $p) use ($batchId, $now): array {
            return [
                'batch_id' => $batchId,
                'product_uuid' => (string) $p['product_uuid'],
                'sku' => $p['sku'] ?? null,
                'vendor' => $p['vendor'] ?? null,
                'status' => 'queued',
                'attempts' => 0,
                'sync_uuid' => null,
                'last_error' => null,
                'started_at' => null,
                'finished_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $products);

        // If a batch is re-run for the same product, keep the latest status.
        DB::table('job_batch_items')->upsert(
            $rows,
            uniqueBy: ['batch_id', 'product_uuid'],
            update: ['sku', 'vendor', 'status', 'attempts', 'sync_uuid', 'last_error', 'started_at', 'finished_at', 'updated_at'],
        );
    }

    public function markRunning(string $batchId, string $productUuid, ?string $syncUuid = null): void
    {
        DB::table('job_batch_items')->updateOrInsert(
            ['batch_id' => $batchId, 'product_uuid' => $productUuid],
            [
                'status' => 'running',
                'sync_uuid' => $syncUuid,
                'attempts' => DB::raw('COALESCE(attempts, 0) + 1'),
                'started_at' => DB::raw('COALESCE(started_at, NOW())'),
                'updated_at' => Carbon::now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ],
        );
    }

    public function markSucceeded(string $batchId, string $productUuid): void
    {
        DB::table('job_batch_items')->updateOrInsert(
            ['batch_id' => $batchId, 'product_uuid' => $productUuid],
            [
                'status' => 'succeeded',
                'finished_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ],
        );
    }

    public function markFailed(string $batchId, string $productUuid, string $error): void
    {
        DB::table('job_batch_items')->updateOrInsert(
            ['batch_id' => $batchId, 'product_uuid' => $productUuid],
            [
                'status' => 'failed',
                'last_error' => mb_substr($error, 0, 4000),
                'finished_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ],
        );
    }

    public function markSkipped(string $batchId, string $productUuid, string $reason): void
    {
        DB::table('job_batch_items')->updateOrInsert(
            ['batch_id' => $batchId, 'product_uuid' => $productUuid],
            [
                'status' => 'skipped',
                'last_error' => mb_substr($reason, 0, 4000),
                'finished_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ],
        );
    }

    public function getSummary(string $batchId, int $limitPerSection = 25): array
    {
        $limitPerSection = max(1, min($limitPerSection, 200));

        $countsRaw = DB::table('job_batch_items')
            ->select(['status', DB::raw('COUNT(*) as c')])
            ->where('batch_id', '=', $batchId)
            ->groupBy('status')
            ->get()
            ->all();

        $counts = [
            'queued' => 0,
            'running' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
        foreach ($countsRaw as $r) {
            $status = is_string($r->status ?? null) ? (string) $r->status : '';
            $c = (int) ($r->c ?? 0);
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $c;
            }
        }

        $running = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->where('status', '=', 'running')
            ->orderByDesc('started_at')
            ->limit($limitPerSection)
            ->get()
            ->map(static fn (object $r): array => self::rowToArray($r))
            ->all();

        $queued = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->where('status', '=', 'queued')
            ->orderBy('id')
            ->limit($limitPerSection)
            ->get()
            ->map(static fn (object $r): array => self::rowToArray($r))
            ->all();

        $done = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->whereIn('status', ['succeeded', 'failed', 'skipped'])
            ->orderByDesc('finished_at')
            ->limit($limitPerSection)
            ->get()
            ->map(static fn (object $r): array => self::rowToArray($r))
            ->all();

        return [
            'counts' => $counts,
            'running' => $running,
            'queued' => $queued,
            'done' => $done,
        ];
    }

    public function backfillFromQueueTables(string $batchId): void
    {
        $existing = (int) DB::table('job_batch_items')->where('batch_id', '=', $batchId)->count();
        if ($existing === 0) {
            // Backfill queued/running from the database queue table.
            /** @var array<int, object> $jobs */
            $jobs = DB::table('jobs')
                ->select(['payload', 'reserved_at', 'attempts'])
                ->where('queue', '=', 'pdp_sync')
                ->where('payload', 'like', '%SyncPlamodAssetsJob%')
                ->limit(2000)
                ->get()
                ->all();

            $jobInfos = [];
            $productUuids = [];
            foreach ($jobs as $row) {
                $payload = json_decode((string) $row->payload, true);
                if (!is_array($payload)) continue;
                $command = $payload['data']['command'] ?? null;
                if (!is_string($command) || $command === '') continue;

                $job = @unserialize($command, ['allowed_classes' => [SyncPlamodAssetsJob::class]]);
                if (!($job instanceof SyncPlamodAssetsJob)) continue;
                if (($job->batchId ?? null) !== $batchId) continue;

                $productUuid = (string) $job->productUuid;
                $productUuids[] = $productUuid;

                $jobInfos[] = [
                    'product_uuid' => $productUuid,
                    'status' => ($row->reserved_at ?? null) ? 'running' : 'queued',
                    'attempts' => is_int($row->attempts ?? null) ? (int) $row->attempts : 0,
                    'sync_uuid' => $job->syncUuid ?? null,
                    'started_at' => ($row->reserved_at ?? null) !== null ? Carbon::createFromTimestamp((int) $row->reserved_at) : null,
                ];
            }

            $productsMap = [];
            if ($productUuids !== []) {
                /** @var array<int, object> $rows */
                $rows = DB::table('products')
                    ->select(['uuid', 'sku', 'vendor'])
                    ->whereIn('uuid', array_values(array_unique($productUuids)))
                    ->get()
                    ->all();
                foreach ($rows as $r) {
                    $productsMap[(string) $r->uuid] = [
                        'sku' => $r->sku !== null ? (string) $r->sku : null,
                        'vendor' => $r->vendor !== null ? (string) $r->vendor : null,
                    ];
                }
            }

            $queuedProducts = array_map(static function (array $info) use ($batchId, $productsMap): array {
                $meta = $productsMap[$info['product_uuid']] ?? ['sku' => null, 'vendor' => null];

                return [
                    'batch_id' => $batchId,
                    'product_uuid' => $info['product_uuid'],
                    'sku' => $meta['sku'],
                    'vendor' => $meta['vendor'],
                    'status' => $info['status'],
                    'attempts' => $info['attempts'],
                    'sync_uuid' => $info['sync_uuid'],
                    'last_error' => null,
                    'started_at' => $info['started_at'],
                    'finished_at' => null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
            }, $jobInfos);

            if ($queuedProducts !== []) {
                DB::table('job_batch_items')->upsert(
                    $queuedProducts,
                    uniqueBy: ['batch_id', 'product_uuid'],
                    update: ['status', 'attempts', 'sync_uuid', 'started_at', 'updated_at'],
                );
            }

            // Backfill failed from failed_jobs table.
            /** @var array<int, object> $failed */
            $failed = DB::table('failed_jobs')
                ->select(['payload', 'exception', 'failed_at'])
                ->where('payload', 'like', '%SyncPlamodAssetsJob%')
                ->orderByDesc('failed_at')
                ->limit(2000)
                ->get()
                ->all();

            $failedInfos = [];
            $failedProductUuids = [];
            foreach ($failed as $row) {
                $payload = json_decode((string) $row->payload, true);
                if (!is_array($payload)) continue;
                $command = $payload['data']['command'] ?? null;
                if (!is_string($command) || $command === '') continue;

                $job = @unserialize($command, ['allowed_classes' => [SyncPlamodAssetsJob::class]]);
                if (!($job instanceof SyncPlamodAssetsJob)) continue;
                if (($job->batchId ?? null) !== $batchId) continue;

                $productUuid = (string) $job->productUuid;
                $failedProductUuids[] = $productUuid;

                $err = is_string($row->exception ?? null) ? (string) $row->exception : '';
                $errHead = $err !== '' ? strtok($err, "\n") : 'failed';
                $failedAt = $row->failed_at !== null ? Carbon::parse((string) $row->failed_at) : Carbon::now();

                $failedInfos[] = [
                    'product_uuid' => $productUuid,
                    'sync_uuid' => $job->syncUuid ?? null,
                    'last_error' => mb_substr((string) $errHead, 0, 4000),
                    'finished_at' => $failedAt,
                ];
            }

            $failedProductsMap = [];
            if ($failedProductUuids !== []) {
                /** @var array<int, object> $rows */
                $rows = DB::table('products')
                    ->select(['uuid', 'sku', 'vendor'])
                    ->whereIn('uuid', array_values(array_unique($failedProductUuids)))
                    ->get()
                    ->all();
                foreach ($rows as $r) {
                    $failedProductsMap[(string) $r->uuid] = [
                        'sku' => $r->sku !== null ? (string) $r->sku : null,
                        'vendor' => $r->vendor !== null ? (string) $r->vendor : null,
                    ];
                }
            }

            $failedRows = array_map(static function (array $info) use ($batchId, $failedProductsMap): array {
                $meta = $failedProductsMap[$info['product_uuid']] ?? ['sku' => null, 'vendor' => null];

                return [
                    'batch_id' => $batchId,
                    'product_uuid' => $info['product_uuid'],
                    'sku' => $meta['sku'],
                    'vendor' => $meta['vendor'],
                    'status' => 'failed',
                    'attempts' => 1,
                    'sync_uuid' => $info['sync_uuid'],
                    'last_error' => $info['last_error'],
                    'started_at' => null,
                    'finished_at' => $info['finished_at'],
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
            }, $failedInfos);

            if ($failedRows !== []) {
                DB::table('job_batch_items')->upsert(
                    $failedRows,
                    uniqueBy: ['batch_id', 'product_uuid'],
                    update: ['status', 'sync_uuid', 'last_error', 'finished_at', 'updated_at'],
                );
            }
        }

        // Enrich SKU/vendor for already-tracked items (portable across MySQL/SQLite used by tests).
        /** @var array<int, string> $missingMetaUuids */
        $missingMetaUuids = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->where(static function ($q): void {
                $q->whereNull('sku')->orWhereNull('vendor');
            })
            ->limit(2000)
            ->pluck('product_uuid')
            ->all();

        if ($missingMetaUuids === []) {
            return;
        }

        /** @var array<int, object> $rows */
        $rows = DB::table('products')
            ->select(['uuid', 'sku', 'vendor'])
            ->whereIn('uuid', $missingMetaUuids)
            ->get()
            ->all();

        $now = Carbon::now();
        foreach ($rows as $r) {
            DB::table('job_batch_items')
                ->where('batch_id', '=', $batchId)
                ->where('product_uuid', '=', (string) $r->uuid)
                ->update([
                    'sku' => $r->sku !== null ? (string) $r->sku : null,
                    'vendor' => $r->vendor !== null ? (string) $r->vendor : null,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowToArray(object $r): array
    {
        return [
            'product_uuid' => (string) $r->product_uuid,
            'sku' => $r->sku !== null ? (string) $r->sku : null,
            'vendor' => $r->vendor !== null ? (string) $r->vendor : null,
            'status' => (string) $r->status,
            'attempts' => (int) $r->attempts,
            'sync_uuid' => $r->sync_uuid !== null ? (string) $r->sync_uuid : null,
            'last_error' => $r->last_error !== null ? (string) $r->last_error : null,
            'started_at' => $r->started_at !== null ? Carbon::parse((string) $r->started_at)->toISOString() : null,
            'finished_at' => $r->finished_at !== null ? Carbon::parse((string) $r->finished_at)->toISOString() : null,
        ];
    }
}


