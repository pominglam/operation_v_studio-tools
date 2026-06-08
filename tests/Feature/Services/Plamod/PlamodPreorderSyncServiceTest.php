<?php

declare(strict_types=1);

use App\Jobs\Plamod\DownloadPlamodPreorderImageJob;
use App\Models\PlamodPreorder;
use App\Models\PlamodPreorderSyncLog;
use App\Services\Plamod\PlamodPreorderSyncService;
use App\Services\Products\Http\PlamodScraper;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('runs csv import and image cleanup during sync refresh', function (): void {
    Queue::fake();

    $stalePath = 'plamod/preorder-images/stale-sync.jpg';
    Storage::disk('local')->put($stalePath, 'stale');
    PlamodPreorder::query()->create([
        'sku' => 'STALE-SYNC',
        'product_name' => 'Stale',
        'image_storage_path' => $stalePath,
        'image_download_status' => PlamodPreorder::IMAGE_STATUS_COMPLETED,
        'dropped_at' => now()->subDays(16),
    ]);

    $csv = <<<'CSV'
SKU,Barcode,Product Name,Series,Release Date,Manufacturer,Category,Price Stock,Price Preorder,Price Backorder,Quantity Preorder,PO Due Date,ETA Date,Image URL
SYNC001,111,Sync Kit,HG,2026-07-01,BANDAI,Gunpla,10.00,9.00,11.00,5,2026-06-10,2026-09-01,https://example.com/a.png
CSV;
    Storage::disk('local')->put('plamod/preorder_exports/test.csv', $csv);

    $bandaiCsv = <<<'CSV'
SKU,Barcode,Product Name,Series,Release Date,Manufacturer,Category,Price Stock,Price Preorder,Price Backorder,Quantity Preorder,PO Due Date,ETA Date,Image URL
0225768,4573102667427,RE 1/100 VIGNA-GHINA,Gundam F90-F91,2018-05-01,BANDAI HOBBY,Gundam F90-F91,37.70,36.59,,0,2026-06-09,2027-01-31,https://example.com/vigna.png
CSV;
    Storage::disk('local')->put('plamod/manufacturer_preorder_exports/bandai.csv', $bandaiCsv);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('exportPreordersCsv')->once()->andReturn([
        'ok' => true,
        'csv_storage_path' => 'plamod/preorder_exports/test.csv',
    ]);
    $scraper->shouldReceive('exportManufacturerPreordersCsv')->once()->with(1)->andReturn([
        'ok' => true,
        'csv_storage_path' => 'plamod/manufacturer_preorder_exports/bandai.csv',
        'row_count' => 1,
        'has_vigna_sku' => true,
        'has_vigna_name' => true,
    ]);
    app()->instance(PlamodScraper::class, $scraper);

    $log = PlamodPreorderSyncLog::query()->create([
        'status' => 'queued',
        'started_at' => now(),
        'counts_json' => [],
    ]);

    app(PlamodPreorderSyncService::class)->run((int) $log->id);

    $log->refresh();
    expect($log->status)->toBe('running');
    expect($log->counts_json['images_deleted'] ?? null)->toBe(1);
    expect($log->counts_json['rows_upserted'] ?? null)->toBe(2);
    expect($log->counts_json['merged_csv_sources'] ?? null)->toBe(2);
    expect($log->counts_json['manufacturer_has_vigna_sku'] ?? null)->toBeTrue();
    expect(Storage::disk('local')->exists($stalePath))->toBeFalse();

    Queue::assertPushed(DownloadPlamodPreorderImageJob::class);
});
