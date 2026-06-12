<?php

declare(strict_types=1);

use App\Models\PlamodPreorder;
use App\Services\Plamod\PlamodPreorderCsvImportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

it('imports preorder csv and marks missing skus as dropped', function (): void {
    $csv = <<<'CSV'
SKU,Barcode,Product Name,Series,Release Date,Manufacturer,Category,Price Stock,Price Preorder,Price Backorder,Quantity Preorder,PO Due Date,ETA Date,Image URL
ABC123,111,Test Kit A,HG,2026-07-01,BANDAI,Gunpla,10.00,9.00,11.00,5,2026-06-10,2026-09-01,https://example.com/a.png
DEF456,222,Test Kit B,MG,2026-08-01,BANDAI,Gunpla,20.00,19.00,21.00,2,2026-06-11,2026-10-01,https://example.com/b.png
CSV;

    Storage::disk('local')->put('plamod/test-preorders.csv', $csv);

    PlamodPreorder::query()->create([
        'sku' => 'OLD999',
        'product_name' => 'Old row',
        'image_download_status' => 'pending',
        'last_seen_at' => now()->subDays(4),
    ]);

    $service = app(PlamodPreorderCsvImportService::class);
    $result = $service->importFromStoragePath('plamod/test-preorders.csv');

    expect($result['rows_parsed'])->toBe(2);
    expect($result['rows_upserted'])->toBe(2);
    expect($result['rows_dropped'])->toBe(1);
    expect($result['rows_skipped'])->toBe(0);

    $this->assertDatabaseHas('plamod_preorders', [
        'sku' => 'ABC123',
        'product_name' => 'Test Kit A',
        'manufacturer' => 'BANDAI',
        'dropped_at' => null,
    ]);

    $old = PlamodPreorder::query()->where('sku', 'OLD999')->firstOrFail();
    expect($old->dropped_at)->not->toBeNull();
});

it('skips malformed csv continuation rows with invalid sku values', function (): void {
    $csv = <<<'CSV'
SKU,Barcode,Product Name,Series,Release Date,Manufacturer,Category,Price Stock,Price Preorder,Price Backorder,Quantity Preorder,PO Due Date,ETA Date,Image URL
5004801,4902425048017,1/144 Weapon Set,Mobile Suit Gundam,2000-01-01,BANDAI HOBBY,Others,2.78,2.70,,80,2026-06-09,2027-01-31,https://example.com/a.png
",Mobile Suit Gundam,2000-01-01,1,BANDAI HOBBY,Others,2.78,2.70,,80,1,0,0,2026-06-09,2026-06-09,2027-01-31,https://example.com/orphan.png,2,1,2026-06-08T10:02:23.417Z"
CSV;

    Storage::disk('local')->put('plamod/broken-preorders.csv', $csv);

    $result = app(PlamodPreorderCsvImportService::class)->importFromStoragePath('plamod/broken-preorders.csv');

    expect($result['rows_parsed'])->toBe(2);
    expect($result['rows_skipped'])->toBe(1);
    expect($result['rows_upserted'])->toBe(1);

    $this->assertDatabaseHas('plamod_preorders', [
        'sku' => '5004801',
        'product_name' => '1/144 Weapon Set',
    ]);
});

it('preserves existing populated fields when a sparse csv row omits them', function (): void {
    PlamodPreorder::query()->create([
        'sku' => '0225768',
        'product_name' => 'RE 1/100 VIGNA-GHINA',
        'manufacturer' => 'BANDAI HOBBY',
        'category' => 'Plastic Model Kits',
        'price_preorder' => '36.59',
        'source_image_url' => 'https://example.com/vigna.png',
        'image_download_status' => 'pending',
        'last_seen_at' => now()->subHour(),
    ]);

    $sparseCsv = <<<'CSV'
SKU,Barcode,Product Name,Series,Release Date,Manufacturer,Category,Price Stock,Price Preorder,Price Backorder,Quantity Preorder,PO Due Date,ETA Date,Image URL
0225768,,RE 1/100 VIGNA-GHINA,,,BANDAI HOBBY,Plastic Model Kits,,,,,,,
CSV;

    Storage::disk('local')->put('plamod/sparse-preorders.csv', $sparseCsv);

    app(PlamodPreorderCsvImportService::class)->importFromStoragePath('plamod/sparse-preorders.csv');

    $row = PlamodPreorder::query()->where('sku', '0225768')->firstOrFail();
    expect((string) $row->price_preorder)->toBe('36.59');
    expect($row->source_image_url)->toBe('https://example.com/vigna.png');
});

it('does not drop recently seen skus missing from an incomplete csv sync', function (): void {
    $csv = <<<'CSV'
SKU,Barcode,Product Name,Series,Release Date,Manufacturer,Category,Price Stock,Price Preorder,Price Backorder,Quantity Preorder,PO Due Date,ETA Date,Image URL
ABC123,111,Test Kit A,HG,2026-07-01,BANDAI,Gunpla,10.00,9.00,11.00,5,2026-06-10,2026-09-01,https://example.com/a.png
CSV;

    Storage::disk('local')->put('plamod/partial-preorders.csv', $csv);

    PlamodPreorder::query()->create([
        'sku' => 'RECENT-MISS',
        'product_name' => 'Still on Plamod',
        'image_download_status' => 'pending',
        'last_seen_at' => now()->subDay(),
    ]);

    app(PlamodPreorderCsvImportService::class)->importFromStoragePath('plamod/partial-preorders.csv');

    $recent = PlamodPreorder::query()->where('sku', 'RECENT-MISS')->firstOrFail();
    expect($recent->dropped_at)->toBeNull();
});

it('parses plamod short month-day dates for preorder closing and eta', function (): void {
    Carbon::setTestNow('2026-06-05 12:00:00');

    $csv = <<<'CSV'
SKU,Barcode,Product Name,Series,Release Date,Manufacturer,Category,Price Stock,Price Preorder,Price Backorder,Quantity Preorder,PO Due Date,ETA Date,Image URL
0225768,4573102667427,RE 1/100 VIGNA-GHINA,Gundam F90-F91,May 1 2018,BANDAI HOBBY,Plastic Model Kits,37.70,36.59,,2,Jun 9,JAN 31,https://example.com/vigna.png
CSV;

    Storage::disk('local')->put('plamod/vigna-dates.csv', $csv);

    app(PlamodPreorderCsvImportService::class)->importFromStoragePath('plamod/vigna-dates.csv');

    $row = PlamodPreorder::query()->where('sku', '0225768')->firstOrFail();
    expect($row->release_date?->toDateString())->toBe('2018-05-01');
    expect($row->po_due_date?->toDateString())->toBe('2026-06-09');
    expect($row->eta_date?->toDateString())->toBe('2027-01-31');
    expect($row->quantity_preorder)->toBe(2);
});
