<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('lists recent job batches', function (): void {
    DB::table('job_batches')->insert([
        'id' => 'test-batch-1',
        'name' => 'sync_missing_pdp_info',
        'total_jobs' => 10,
        'pending_jobs' => 7,
        'failed_jobs' => 1,
        'failed_job_ids' => '[]',
        'options' => null,
        'cancelled_at' => null,
        'created_at' => 123,
        'finished_at' => null,
    ]);

    $res = $this->getJson('/api/v1/job-batches?limit=5');

    $res->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.0.id', 'test-batch-1')
        ->assertJsonPath('data.0.name', 'sync_missing_pdp_info')
        ->assertJsonPath('data.0.total_jobs', 10)
        ->assertJsonPath('data.0.pending_jobs', 7)
        ->assertJsonPath('data.0.failed_jobs', 1);
});


