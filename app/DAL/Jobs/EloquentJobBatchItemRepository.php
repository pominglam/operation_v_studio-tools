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
                'debug_log' => isset($p['debug_log']) && is_string($p['debug_log']) && trim($p['debug_log']) !== '' ? trim((string) $p['debug_log']) : null,
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
            update: ['sku', 'vendor', 'status', 'attempts', 'sync_uuid', 'last_error', 'debug_log', 'started_at', 'finished_at', 'updated_at'],
        );
    }

    public function markRunning(string $batchId, string $productUuid, ?string $syncUuid = null): void
    {
        $now = Carbon::now();

        // First try update (most common path: row already created by insertQueued()).
        $updated = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->where('product_uuid', '=', $productUuid)
            ->update([
                'status' => 'running',
                'sync_uuid' => $syncUuid,
                'attempts' => DB::raw('COALESCE(attempts, 0) + 1'),
                // Reset per-run trace so the UI shows the current attempt clearly.
                'debug_log' => null,
                // Portable across MySQL/SQLite:
                'started_at' => DB::raw('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'updated_at' => $now,
            ]);

        if ($updated > 0) {
            return;
        }

        // Fallback insert path (portable for SQLite tests; avoids referencing column names in VALUES).
        DB::table('job_batch_items')->insert([
            'batch_id' => $batchId,
            'product_uuid' => $productUuid,
            'sku' => null,
            'vendor' => null,
            'status' => 'running',
            'attempts' => 1,
            'sync_uuid' => $syncUuid,
            'last_error' => null,
            'debug_log' => null,
            'started_at' => $now,
            'finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function markSucceeded(string $batchId, string $productUuid): void
    {
        $now = Carbon::now();

        $updated = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->where('product_uuid', '=', $productUuid)
            ->update([
                'status' => 'succeeded',
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated > 0) {
            return;
        }

        DB::table('job_batch_items')->insert([
            'batch_id' => $batchId,
            'product_uuid' => $productUuid,
            'sku' => null,
            'vendor' => null,
            'status' => 'succeeded',
            'attempts' => 1,
            'sync_uuid' => null,
            'last_error' => null,
            'debug_log' => null,
            'started_at' => null,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function appendDebugLog(string $batchId, string $productUuid, string $line): void
    {
        $line = trim((string) $line);
        if ($line === '') return;

        // Portable across MySQL/SQLite used by tests: read -> append -> update.
        $existing = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->where('product_uuid', '=', $productUuid)
            ->value('debug_log');

        $prev = is_string($existing) ? $existing : '';
        $next = $prev === '' ? $line : ($prev."\n".$line);

        // Avoid unbounded growth (keep the most recent tail).
        $maxLen = 12000;
        if (mb_strlen($next) > $maxLen) {
            $next = mb_substr($next, -$maxLen);
        }

        DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->where('product_uuid', '=', $productUuid)
            ->update([
                'debug_log' => $next,
                'updated_at' => Carbon::now(),
            ]);
    }

    public function markFailed(string $batchId, string $productUuid, string $error): void
    {
        $now = Carbon::now();
        $err = mb_substr($error, 0, 4000);

        $updated = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->where('product_uuid', '=', $productUuid)
            ->update([
                'status' => 'failed',
                'last_error' => $err,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated > 0) {
            return;
        }

        DB::table('job_batch_items')->insert([
            'batch_id' => $batchId,
            'product_uuid' => $productUuid,
            'sku' => null,
            'vendor' => null,
            'status' => 'failed',
            'attempts' => 1,
            'sync_uuid' => null,
            'last_error' => $err,
            'debug_log' => null,
            'started_at' => null,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function markSkipped(string $batchId, string $productUuid, string $reason): void
    {
        $now = Carbon::now();
        $err = mb_substr($reason, 0, 4000);

        $updated = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->where('product_uuid', '=', $productUuid)
            ->update([
                'status' => 'skipped',
                'last_error' => $err,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated > 0) {
            return;
        }

        DB::table('job_batch_items')->insert([
            'batch_id' => $batchId,
            'product_uuid' => $productUuid,
            'sku' => null,
            'vendor' => null,
            'status' => 'skipped',
            'attempts' => 1,
            'sync_uuid' => null,
            'last_error' => $err,
            'debug_log' => null,
            'started_at' => null,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

        // Enrich list items with product "name" (products.description) for better UI readability.
        $select = [
            'j.product_uuid',
            'j.sku',
            'j.vendor',
            'j.status',
            'j.attempts',
            'j.sync_uuid',
            'j.last_error',
            'j.debug_log',
            'j.started_at',
            'j.finished_at',
            'p.description as product_name',
        ];

        $running = DB::table('job_batch_items as j')
            ->leftJoin('products as p', 'p.uuid', '=', 'j.product_uuid')
            ->select($select)
            ->where('j.batch_id', '=', $batchId)
            ->where('j.status', '=', 'running')
            ->orderByDesc('j.started_at')
            ->limit($limitPerSection)
            ->get()
            ->map(static fn (object $r): array => self::rowToArray($r))
            ->all();

        $queued = DB::table('job_batch_items as j')
            ->leftJoin('products as p', 'p.uuid', '=', 'j.product_uuid')
            ->select($select)
            ->where('j.batch_id', '=', $batchId)
            ->where('j.status', '=', 'queued')
            ->orderBy('j.id')
            ->limit($limitPerSection)
            ->get()
            ->map(static fn (object $r): array => self::rowToArray($r))
            ->all();

        $done = DB::table('job_batch_items as j')
            ->leftJoin('products as p', 'p.uuid', '=', 'j.product_uuid')
            ->select($select)
            ->where('j.batch_id', '=', $batchId)
            ->whereIn('j.status', ['succeeded', 'failed', 'skipped'])
            ->orderByDesc('j.finished_at')
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
            'product_name' => $r->product_name !== null ? (string) $r->product_name : null,
            'vendor' => $r->vendor !== null ? (string) $r->vendor : null,
            'status' => (string) $r->status,
            'attempts' => (int) $r->attempts,
            'sync_uuid' => $r->sync_uuid !== null ? (string) $r->sync_uuid : null,
            'last_error' => $r->last_error !== null ? (string) $r->last_error : null,
            'debug_log' => $r->debug_log !== null ? (string) $r->debug_log : null,
            'started_at' => $r->started_at !== null ? Carbon::parse((string) $r->started_at)->toISOString() : null,
            'finished_at' => $r->finished_at !== null ? Carbon::parse((string) $r->finished_at)->toISOString() : null,
        ];
    }

    public function listProductUuidsByStatus(string $batchId, array $statuses): array
    {
        $statuses = array_values(array_unique(array_filter(array_map('strval', $statuses), static fn (string $v): bool => trim($v) !== '')));
        if ($statuses === []) return [];

        /** @var array<int, string> $rows */
        $rows = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->whereIn('status', $statuses)
            ->orderBy('updated_at', 'desc')
            ->pluck('product_uuid')
            ->all();

        return array_values(array_unique(array_filter(array_map('strval', $rows), static fn (string $v): bool => trim($v) !== '')));
    }

    public function getAnyDebugLog(string $batchId): ?string
    {
        $row = DB::table('job_batch_items')
            ->where('batch_id', '=', $batchId)
            ->whereNotNull('debug_log')
            ->orderBy('updated_at', 'desc')
            ->value('debug_log');

        $s = is_string($row) ? trim($row) : '';
        return $s !== '' ? $s : null;
    }
}


