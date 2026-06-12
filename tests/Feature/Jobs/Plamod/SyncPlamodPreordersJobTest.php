<?php

declare(strict_types=1);

use App\Jobs\Plamod\SyncPlamodPreordersJob;
use App\Models\PlamodPreorderSyncLog;
use App\Services\Plamod\PlamodPreorderSyncCheckpoint;
use Illuminate\Support\Facades\Queue;

it('detects recoverable worker timeout failures', function (): void {
    expect(PlamodPreorderSyncCheckpoint::isRecoverableFailure('App\\Jobs\\Plamod\\SyncPlamodPreordersJob has been attempted too many times.'))
        ->toBeTrue();
    expect(PlamodPreorderSyncCheckpoint::isRecoverableFailure('cURL error 28: Operation timed out'))
        ->toBeTrue();
});

it('auto-resumes a sync job when checkpoint progress exists', function (): void {
    Queue::fake();

    $log = PlamodPreorderSyncLog::query()->create([
        'status' => 'running',
        'started_at' => now(),
        'counts_json' => [
            'phase' => 'manufacturer_export',
            'checkpoint_hub_csv_path' => 'plamod/preorder_exports/hub.csv',
            'checkpoint_completed_filter_keys' => ['category_line:SD BB'],
            'checkpoint_manufacturer_csv_paths' => ['plamod/manufacturer_preorder_exports/sd-bb.csv'],
            'checkpoint_manufacturer_succeeded' => 1,
        ],
    ]);

    $job = new SyncPlamodPreordersJob((int) $log->id, resume: false, autoResumeAttempt: 0);
    $job->failed(new RuntimeException('SyncPlamodPreordersJob has been attempted too many times.'));

    $log->refresh();
    expect($log->status)->toBe('queued');
    expect($log->error_summary)->toBeNull();
    expect($log->counts_json['auto_resume_attempt'] ?? null)->toBe(1);

    Queue::assertPushed(SyncPlamodPreordersJob::class, function (SyncPlamodPreordersJob $job) use ($log): bool {
        return $job->syncLogId === (int) $log->id
            && $job->resume === true
            && $job->autoResumeAttempt === 1;
    });
});

it('does not auto-resume when max attempts are exhausted', function (): void {
    Queue::fake();

    $log = PlamodPreorderSyncLog::query()->create([
        'status' => 'running',
        'started_at' => now(),
        'counts_json' => [
            'checkpoint_hub_csv_path' => 'plamod/preorder_exports/hub.csv',
            'auto_resume_attempt' => 5,
        ],
    ]);

    $job = new SyncPlamodPreordersJob((int) $log->id, resume: true, autoResumeAttempt: 5);
    $job->failed(new RuntimeException('SyncPlamodPreordersJob has been attempted too many times.'));

    $log->refresh();
    expect($log->status)->toBe('failed');
    Queue::assertNothingPushed();
});
