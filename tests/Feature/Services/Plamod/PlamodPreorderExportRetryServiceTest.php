<?php

declare(strict_types=1);

use App\Enums\PlamodPreorderManufacturerFilterDecision;
use App\Enums\PlamodPreorderManufacturerFilterType;
use App\Models\PlamodPreorderManufacturerFilter;
use App\Services\Plamod\PlamodPreorderExportRetryService;
use App\Services\Plamod\PlamodPreorderSyncFailureRecorder;
use App\Services\Products\Http\PlamodScraper;
use Illuminate\Support\Facades\Storage;

it('retries hub export after a retryable login failure', function (): void {
    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('exportPreordersCsv')
        ->once()
        ->andReturn(['ok' => false, 'error_message' => 'Plamod login failed for preorders page']);
    $scraper->shouldReceive('resetScraperSessions')->once()->andReturn(['ok' => true]);
    $scraper->shouldReceive('exportPreordersCsv')
        ->once()
        ->andReturn(['ok' => true, 'csv_storage_path' => 'plamod/preorder_exports/hub.csv']);
    app()->instance(PlamodScraper::class, $scraper);

    $result = app(PlamodPreorderExportRetryService::class)->exportHubCsv(99, new PlamodPreorderSyncFailureRecorder);

    expect($result['ok'])->toBeTrue();
    expect($result['attempts'])->toBe(2);
});

it('retries manufacturer export and records failures to jsonl', function (): void {
    Storage::fake('local');

    PlamodPreorderManufacturerFilter::query()->create([
        'manufacturer_id' => 1,
        'filter_type' => PlamodPreorderManufacturerFilterType::Series,
        'name' => 'Mobile Suit Gundam',
        'plamod_preorder_count' => 5,
        'decision' => PlamodPreorderManufacturerFilterDecision::Include,
    ]);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('exportManufacturerPreordersCsv')
        ->times(4)
        ->with(1, 'Mobile Suit Gundam', null)
        ->andReturn(
            ['ok' => false, 'error_message' => 'Plamod login failed for manufacturer series preorder export'],
            ['ok' => false, 'error_message' => 'Plamod login failed for manufacturer series preorder export'],
            ['ok' => false, 'error_message' => 'Plamod login failed for manufacturer series preorder export'],
            ['ok' => true, 'csv_storage_path' => 'plamod/manufacturer_preorder_exports/recovered.csv', 'row_count' => 12],
        );
    $scraper->shouldReceive('resetScraperSessions')->atLeast()->once()->andReturn(['ok' => true]);
    app()->instance(PlamodScraper::class, $scraper);

    $recorder = new PlamodPreorderSyncFailureRecorder;
    $result = app(PlamodPreorderExportRetryService::class)->exportManufacturerFilters(42, PlamodPreorderManufacturerFilter::query()->get(), $recorder);

    expect($result['manufacturer_export_succeeded'])->toBe(1);
    expect($result['manufacturer_export_failed'])->toBe(0);
    expect($result['manufacturer_export_retried'])->toBeGreaterThan(0);
    expect(Storage::disk('local')->exists('plamod/preorder_sync_logs/sync-42-failures.jsonl'))->toBeTrue();
});

it('reports manufacturer export progress after each filter attempt completes', function (): void {
    Storage::fake('local');

    PlamodPreorderManufacturerFilter::query()->create([
        'manufacturer_id' => 1,
        'filter_type' => PlamodPreorderManufacturerFilterType::Series,
        'name' => 'Mobile Suit Gundam',
        'plamod_preorder_count' => 5,
        'decision' => PlamodPreorderManufacturerFilterDecision::Include,
    ]);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('exportManufacturerPreordersCsv')
        ->times(4)
        ->andReturn(
            ['ok' => false, 'error_message' => 'timeout'],
            ['ok' => false, 'error_message' => 'timeout'],
            ['ok' => false, 'error_message' => 'timeout'],
            ['ok' => true, 'csv_storage_path' => 'plamod/manufacturer_preorder_exports/recovered.csv', 'row_count' => 12],
        );
    $scraper->shouldReceive('resetScraperSessions')->atLeast()->once()->andReturn(['ok' => true]);
    app()->instance(PlamodScraper::class, $scraper);

    $progressCalls = [];
    app(PlamodPreorderExportRetryService::class)->exportManufacturerFilters(
        42,
        PlamodPreorderManufacturerFilter::query()->get(),
        new PlamodPreorderSyncFailureRecorder,
        function (int $processed, int $total, $filter, bool $ok, bool $recoveryPass, int $succeeded, int $failed) use (&$progressCalls): void {
            $progressCalls[] = compact('processed', 'total', 'ok', 'recoveryPass', 'succeeded', 'failed');
        },
    );

    expect($progressCalls)->toHaveCount(2);
    expect($progressCalls[0]['processed'])->toBe(1);
    expect($progressCalls[0]['total'])->toBe(1);
    expect($progressCalls[0]['recoveryPass'])->toBeFalse();
    expect($progressCalls[1]['recoveryPass'])->toBeTrue();
    expect($progressCalls[1]['ok'])->toBeTrue();
});
