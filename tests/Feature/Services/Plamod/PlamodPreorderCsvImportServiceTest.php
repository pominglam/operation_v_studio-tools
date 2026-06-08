<?php

declare(strict_types=1);

use App\Models\PlamodPreorder;
use App\Services\Plamod\PlamodPreorderCsvImportService;
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
        'last_seen_at' => now()->subDay(),
    ]);

    $service = app(PlamodPreorderCsvImportService::class);
    $result = $service->importFromStoragePath('plamod/test-preorders.csv');

    expect($result['rows_parsed'])->toBe(2);
    expect($result['rows_upserted'])->toBe(2);
    expect($result['rows_dropped'])->toBe(1);

    $this->assertDatabaseHas('plamod_preorders', [
        'sku' => 'ABC123',
        'product_name' => 'Test Kit A',
        'manufacturer' => 'BANDAI',
        'dropped_at' => null,
    ]);

    $old = PlamodPreorder::query()->where('sku', 'OLD999')->firstOrFail();
    expect($old->dropped_at)->not->toBeNull();
});
