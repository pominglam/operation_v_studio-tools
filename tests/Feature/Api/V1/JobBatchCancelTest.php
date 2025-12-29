<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('cancels a job batch', function (): void {
    DB::table('job_batches')->insert([
        'id' => 'test-batch-cancel-1',
        'name' => 'sync_missing_pdp_info',
        'total_jobs' => 10,
        'pending_jobs' => 10,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => '[]',
        'cancelled_at' => null,
        'created_at' => 123,
        'finished_at' => null,
    ]);

    $this->postJson('/api/v1/job-batches/test-batch-cancel-1/cancel')
        ->assertOk()
        ->assertJsonPath('ok', true);

    $row = DB::table('job_batches')->where('id', '=', 'test-batch-cancel-1')->first();
    expect($row)->not->toBeNull();
    expect($row->cancelled_at)->not->toBeNull();
});


