<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Products\ProductBarcodeImportService;

it('imports barcodes using vendor + sku (and does not overwrite by default)', function (): void {
    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000c0001',
        'sku' => 'MC-01',
        'description' => 'Matte Red',
        'vendor' => 'Stedi',
        'barcode' => null,
    ]);
    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000c0002',
        'sku' => 'MC-02',
        'description' => 'Matte Intense Scarlet',
        'vendor' => 'Stedi',
        'barcode' => '1111111111111',
    ]);

    $csv = <<<CSV
名称,司特力型号,每盒入数,order qty,received qty,english name,,,,,,barcode
MC-01哑光大红（基础色）,MC-01,瓶,10,10,Matte Red,Stedi,MC-01,Matte Red,Stedi MC-01 Matte Red,Stedi MC-01 Matte Red,6975400111974
MC-02哑光浓绯红（基础色）,MC-02,瓶,10,10,Matte Intense Scarlet,Stedi,MC-02,Matte Intense Scarlet,Stedi MC-02 Matte Intense Scarlet,Stedi MC-02 Matte Intense Scarlet,6975400111981
CSV;

    $path = base_path('storage/framework/testing/stedi-barcodes-import-test.csv');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $csv);

    /** @var ProductBarcodeImportService $service */
    $service = app(ProductBarcodeImportService::class);
    $res = $service->importFromShipmentCsv($path, true, false, null, 7);

    expect($res->rowsRead)->toBe(2);
    expect($res->matched)->toBe(2);
    expect($res->updatedCount)->toBe(1); // MC-02 should not be overwritten
    expect($res->skippedCount)->toBe(1);
    expect($res->missingCount)->toBe(0);
    expect($res->ambiguousCount)->toBe(0);

    $p1->refresh();
    expect($p1->barcode)->toBe('6975400111974');
    $p2->refresh();
    expect($p2->barcode)->toBe('1111111111111');
});

it('can overwrite existing barcodes when overwrite=true', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000d0001',
        'sku' => 'MC-02',
        'description' => 'Matte Intense Scarlet',
        'vendor' => 'Stedi',
        'barcode' => '1111111111111',
    ]);

    $csv = <<<CSV
名称,司特力型号,每盒入数,order qty,received qty,english name,,,,,,barcode
MC-02哑光浓绯红（基础色）,MC-02,瓶,10,10,Matte Intense Scarlet,Stedi,MC-02,Matte Intense Scarlet,Stedi MC-02 Matte Intense Scarlet,Stedi MC-02 Matte Intense Scarlet,6975400111981
CSV;

    $path = base_path('storage/framework/testing/stedi-barcodes-import-test-overwrite.csv');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $csv);

    /** @var ProductBarcodeImportService $service */
    $service = app(ProductBarcodeImportService::class);
    $res = $service->importFromShipmentCsv($path, true, true, null, 7);

    expect($res->rowsRead)->toBe(1);
    expect($res->updatedCount)->toBe(1);

    $p->refresh();
    expect($p->barcode)->toBe('6975400111981');
});

it('throws when CSV path is missing', function (): void {
    /** @var ProductBarcodeImportService $service */
    $service = app(ProductBarcodeImportService::class);
    $service->importFromShipmentCsv('storage/framework/testing/does-not-exist.csv', false);
})->throws(InvalidArgumentException::class);

