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
        'latest_unit_cost' => '10.00',
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
        'latest_unit_cost' => '12.34',
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
        'latest_unit_cost' => '66.28',
        'order_qty' => 12,
        'filled_qty' => 12,
        'extended' => '795.36',
    ]);

    $this->assertDatabaseHas('products', [
        'sku' => '5069215',
        'barcode' => '4573102692153',
        'description' => 'RG 1/144 WING GUNDAM ZERO',
        'type' => 'RG',
        'latest_unit_cost' => '42.82',
        'order_qty' => 2,
        'filled_qty' => 2,
        'extended' => '85.64',
    ]);

    $this->assertDatabaseMissing('products', [
        'sku' => 'SUMMARY',
    ]);
});

it('imports Stedi products and computes selling price when multiplier is present', function (): void {
    $csv = implode("\n", [
        '名称,司特力型号,每盒入数,Wholesale price HKD,Wholesale price CAD,order qty,HKD,CAD,Multiplier,Price with multiplier,Quantity received,Barcode',
        '单刃模型钳,MS-104,个,"HK$30.7 ",5.59,30,"HK$921.9 ",167.62,2.6,14.53,30,',
        '直镊子,MS-11,个,"HK$16.8 ",3.05,5,"HK$83.8 ",15.24,3.7,11.28,5,6975400111004',
        // no multiplier -> no selling price
        '双刃模型钳,MS-100,个,"HK$53.9 ",9.80,,HK$0.0,0.00,,,,',
        // trailing totals row (should not be imported; used for crosscheck)
        ',,,,Total Amount,35,,182.86,,,,',
    ]);

    $file = UploadedFile::fake()->createWithContent('stedi.csv', $csv, 'text/csv');

    $this->postJson('/api/v1/products/import', [
        'file' => $file,
        'format' => 'stedi',
    ])->assertOk()->assertJson(['imported' => 3]);

    $this->assertDatabaseHas('products', [
        'sku' => 'MS-104',
        'vendor' => 'Stedi',
        'latest_unit_cost' => '5.59',
    ]);

    $this->assertDatabaseHas('products', [
        'sku' => 'MS-11',
        'vendor' => 'Stedi',
        'barcode' => '6975400111004',
        'latest_unit_cost' => '3.05',
    ]);

    // 5.59 * 2.6 = 14.534 -> base 14.54 -> selling 14.99
    $product104 = \App\Models\Product::query()->where('sku', 'MS-104')->firstOrFail();
    $this->assertDatabaseHas('product_selling_prices', [
        'product_id' => $product104->id,
        'currency' => 'CAD',
        'selling_price' => '14.99',
    ]);

    // 3.05 * 3.7 = 11.285 -> base 11.29 -> selling 11.99
    $product11 = \App\Models\Product::query()->where('sku', 'MS-11')->firstOrFail();
    $this->assertDatabaseHas('product_selling_prices', [
        'product_id' => $product11->id,
        'currency' => 'CAD',
        'selling_price' => '11.99',
    ]);

    $product100 = \App\Models\Product::query()->where('sku', 'MS-100')->firstOrFail();
    $this->assertDatabaseMissing('product_selling_prices', [
        'product_id' => $product100->id,
    ]);
});

it('blocks Stedi import when SKU or barcode conflicts exist', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'MS-11',
        'barcode' => '6975400111004',
        'description' => 'Existing',
        'vendor' => 'Plamod',
        'price' => '1.00',
    ]);

    $csv = implode("\n", [
        '名称,司特力型号,每盒入数,Wholesale price HKD,Wholesale price CAD,order qty,HKD,CAD,Multiplier,Price with multiplier,Quantity received,Barcode',
        '直镊子,MS-11,个,"HK$16.8 ",3.05,5,"HK$83.8 ",15.24,3.7,11.28,5,6975400111004',
        '弧镊子,MS-12,个,"HK$16.8 ",3.05,5,"HK$83.8 ",15.24,3.7,11.28,5,6975400111004',
    ]);

    $file = UploadedFile::fake()->createWithContent('stedi.csv', $csv, 'text/csv');

    $this->postJson('/api/v1/products/import', [
        'file' => $file,
        'format' => 'stedi',
    ])->assertStatus(422)->assertJson([
        'message' => 'Import blocked: SKU/barcode conflicts found.',
    ])->assertJsonStructure([
        'issues',
    ]);

    // Import should be all-or-nothing for Stedi
    expect(\App\Models\Product::query()->where('vendor', 'Stedi')->count())->toBe(0);
});

it('blocks Stedi import when the trailing totals row does not match computed totals', function (): void {
    $csv = implode("\n", [
        '名称,司特力型号,每盒入数,Wholesale price HKD,Wholesale price CAD,order qty,HKD,CAD,Multiplier,Price with multiplier,Quantity received,Barcode',
        '直镊子,MS-11,个,"HK$16.8 ",3.05,5,"HK$83.8 ",15.24,3.7,11.28,5,',
        ',,,,Total Amount,6,,,,,,',
    ]);

    $file = UploadedFile::fake()->createWithContent('stedi.csv', $csv, 'text/csv');

    $this->postJson('/api/v1/products/import', [
        'file' => $file,
        'format' => 'stedi',
    ])->assertStatus(422)->assertJson([
        'message' => 'Total crosscheck failed: order qty expected 6, got 5. Import cancelled.',
    ]);

    expect(\App\Models\Product::query()->where('vendor', 'Stedi')->count())->toBe(0);
});
