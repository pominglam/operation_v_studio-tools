<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;

it('imports products from a CSV and persists them', function (): void {
    $csv = implode("\n", [
        'SKU,BARCODE,PRODUCT DESCRIPTION,TYPE,PRICE,ORDER,FILLED,EXTENDED',
        '5060358,4573102603586,Orphans HG 1/144 #13 Gundam Astray Blue Frame,,$10.13,2,2,$20.26',
        '5066295,4573102662958,BB372 Gundam Age-3 (Normal/Fortress/Orbital),,$25.65,2,2,$51.30',
        'X-UNK,111,Some random unmatched product,,$1.00,1,1,$1.00',
    ]);

    $file = UploadedFile::fake()->createWithContent('products.csv', $csv, 'text/csv');

    $response = $this->postJson('/api/v1/products/import', [
        'file' => $file,
        'format' => 'plamod',
    ]);

    $response->assertOk()->assertJson([
        'imported' => 3,
    ]);

    $this->assertDatabaseHas('products', [
        'sku' => '5060358',
        'barcode' => '4573102603586',
        'vendor' => 'Plamod',
        'type' => 'Orphans HG',
    ]);

    $this->assertDatabaseHas('products', [
        'sku' => '5066295',
        'barcode' => '4573102662958',
        'vendor' => 'Plamod',
        'type' => 'SD',
    ]);

    $this->assertDatabaseHas('products', [
        'sku' => 'X-UNK',
        'barcode' => '111',
        'vendor' => 'Plamod',
        'type' => 'Others',
    ]);
});

it('rejects an unknown import format', function (): void {
    $csv = implode("\n", [
        'SKU,BARCODE,PRODUCT DESCRIPTION,TYPE,PRICE,ORDER,FILLED,EXTENDED',
        '5060358,4573102603586,HG 1/144 #13 Gundam Astray Blue Frame,HG,$10.13,2,2,$20.26',
    ]);

    $file = UploadedFile::fake()->createWithContent('products.csv', $csv, 'text/csv');

    $this->postJson('/api/v1/products/import', [
        'file' => $file,
        'format' => 'unknown',
    ])->assertStatus(422);
});

it('imports Plamod products when the CSV uses the new UI-aligned column names', function (): void {
    $csv = implode("\n", [
        'SKU,BARCODE,NAME,TYPE,UNIT COST,ORDERED,SHIPPED,TOTAL COST',
        'NEW-1,999,Some random unmatched product,,10.00,2,1,20.00',
    ]);

    $file = UploadedFile::fake()->createWithContent('products.csv', $csv, 'text/csv');

    $this->postJson('/api/v1/products/import', [
        'file' => $file,
        'format' => 'plamod',
    ])->assertOk()->assertJson(['imported' => 1]);

    $this->assertDatabaseHas('products', [
        'sku' => 'NEW-1',
        'barcode' => '999',
        'description' => 'Some random unmatched product',
        'vendor' => 'Plamod',
        'type' => 'Others',
        'price' => '10.00',
        'order_qty' => 2,
        'filled_qty' => 1,
        'extended' => '20.00',
    ]);
});

it('rejects a CSV missing required columns', function (): void {
    $csv = implode("\n", [
        'SKU,PRODUCT DESCRIPTION',
        '5060358,Some product',
    ]);

    $file = UploadedFile::fake()->createWithContent('products.csv', $csv, 'text/csv');

    $response = $this->postJson('/api/v1/products/import', [
        'file' => $file,
    ]);

    $response->assertStatus(422)->assertJson([
        'message' => 'Missing required column: BARCODE',
    ]);
});

it('updates an existing product (by SKU) during import', function (): void {
    $csv1 = implode("\n", [
        'SKU,BARCODE,PRODUCT DESCRIPTION,TYPE,PRICE,ORDER,FILLED,EXTENDED',
        '5060358,4573102603586,HG 1/144 #13 Gundam Astray Blue Frame,HG,$10.13,2,2,$20.26',
    ]);
    $file1 = UploadedFile::fake()->createWithContent('products.csv', $csv1, 'text/csv');

    $this->postJson('/api/v1/products/import', ['file' => $file1])->assertOk();

    $csv2 = implode("\n", [
        'SKU,BARCODE,PRODUCT DESCRIPTION,TYPE,PRICE,ORDER,FILLED,EXTENDED',
        '5060358,9999999999999,HG 1/144 #13 Gundam Astray Blue Frame (updated),HG,$12.34,3,1,$37.02',
    ]);
    $file2 = UploadedFile::fake()->createWithContent('products.csv', $csv2, 'text/csv');

    $this->postJson('/api/v1/products/import', ['file' => $file2])
        ->assertOk()
        ->assertJson(['imported' => 1]);

    // Updated fields
    $this->assertDatabaseHas('products', [
        'sku' => '5060358',
        'barcode' => '9999999999999',
        'description' => 'HG 1/144 #13 Gundam Astray Blue Frame (updated)',
        'vendor' => 'Plamod',
        'price' => '12.34',
        'order_qty' => 3,
        'filled_qty' => 1,
        'extended' => '37.02',
    ]);

    // No duplicates by SKU
    expect(\App\Models\Product::query()->where('sku', '5060358')->count())->toBe(1);
});

it('imports products from an order-details CSV and ignores the trailing summary section', function (): void {
    $csv = implode("\n", [
        'Order ID,SKU,Barcode,Product Name,Qty Ordered,Qty Filled,Unit Price,Tariff Rate (%),Tariff Amount,Line Subtotal (Before Tax),Tax Rate (%),Tax Amount,Line Total (After Tax),Order Type',
        '16863002,5068707,4573102687074,MG 1/100 GUNDAM BARBATOS LUPUS,12,12,66.28,0.00,0.00,795.36,5.00,39.77,835.13,Regular',
        '16863002,5069215,4573102692153,RG 1/144 WING GUNDAM ZERO,2,2,42.82,0.00,0.00,85.64,5.00,4.28,89.92,Regular',
        '',
        'SUMMARY',
        'Order Date,"December 2, 2025 at 06:18 PM"',
        'TOTALS',
        'Grand Total,3267.57',
    ]);

    $file = UploadedFile::fake()->createWithContent('order.csv', $csv, 'text/csv');

    $this->postJson('/api/v1/products/import', ['file' => $file])
        ->assertOk()
        ->assertJson(['imported' => 2]);

    $this->assertDatabaseHas('products', [
        'sku' => '5068707',
        'barcode' => '4573102687074',
        'description' => 'MG 1/100 GUNDAM BARBATOS LUPUS',
        'type' => 'MG',
        'price' => '66.28',
        'order_qty' => 12,
        'filled_qty' => 12,
        'extended' => '795.36',
    ]);

    $this->assertDatabaseHas('products', [
        'sku' => '5069215',
        'barcode' => '4573102692153',
        'description' => 'RG 1/144 WING GUNDAM ZERO',
        'type' => 'RG',
        'price' => '42.82',
        'order_qty' => 2,
        'filled_qty' => 2,
        'extended' => '85.64',
    ]);

    $this->assertDatabaseMissing('products', [
        'sku' => 'SUMMARY',
    ]);
});
