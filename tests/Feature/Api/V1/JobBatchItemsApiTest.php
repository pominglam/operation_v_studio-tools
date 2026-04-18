<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('returns per-product batch item details (queued/running/done)', function (): void {
    $batchId = 'a0ae0689-dd2d-4812-aa44-73b294d283cb';

    // Ensure a batch exists in the table so the controller can find it.
    DB::table('job_batches')->updateOrInsert(
        ['id' => $batchId],
        [
            'name' => 'sync_missing_pdp_info',
            'total_jobs' => 3,
            'pending_jobs' => 2,
            'failed_jobs' => 1,
            'failed_job_ids' => '[]',
            'options' => '[]',
            'created_at' => now()->timestamp,
            'cancelled_at' => null,
            'finished_at' => null,
        ],
    );

    DB::table('job_batch_items')->insert([
        [
            'batch_id' => $batchId,
            'product_uuid' => '00000000-0000-0000-0000-000000070001',
            'sku' => 'SKU-1',
            'vendor' => 'Plamod',
            'status' => 'queued',
            'attempts' => 0,
            'sync_uuid' => null,
            'last_error' => null,
            'debug_log' => null,
            'started_at' => null,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'batch_id' => $batchId,
            'product_uuid' => '00000000-0000-0000-0000-000000070002',
            'sku' => 'SKU-2',
            'vendor' => 'Plamod',
            'status' => 'running',
            'attempts' => 1,
            'sync_uuid' => '00000000-0000-0000-0000-000000070099',
            'last_error' => null,
            'debug_log' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'batch_id' => $batchId,
            'product_uuid' => '00000000-0000-0000-0000-000000070003',
            'sku' => 'SKU-3',
            'vendor' => 'Plamod',
            'status' => 'failed',
            'attempts' => 1,
            'sync_uuid' => '00000000-0000-0000-0000-000000070098',
            'last_error' => 'timeout',
            'debug_log' => "line1\nline2",
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $res = $this->getJson("/api/v1/job-batches/{$batchId}/items?limit=10");

    $res->assertOk();
    $res->assertJsonPath('ok', true);
    $res->assertJsonPath('data.counts.queued', 1);
    $res->assertJsonPath('data.counts.running', 1);
    $res->assertJsonPath('data.counts.failed', 1);
    $res->assertJsonStructure([
        'data' => [
            'counts' => ['queued', 'running', 'succeeded', 'failed', 'skipped'],
            'running',
            'queued',
            'done',
        ],
    ]);
    $res->assertJsonFragment([
        'product_uuid' => '00000000-0000-0000-0000-000000070003',
        'debug_log' => "line1\nline2",
    ]);
});
