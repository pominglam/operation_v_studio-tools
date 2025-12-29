<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductSellingPrice;

it('exports barcoded products sorted by vendor then type then sku with available and selling price', function (): void {
    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000080001',
        'sku' => 'B-002',
        'barcode' => '222',
        'description' => 'Prod B2',
        'type' => 'MG',
        'available_qty' => 5,
        'vendor' => 'Plamod',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p1->id,
        'product_uuid' => $p1->uuid,
        'selling_price' => '19.99',
        'currency' => 'CAD',
    ]);

    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000080002',
        'sku' => 'A-010',
        'barcode' => '111',
        'description' => 'Prod A10',
        'type' => 'HG',
        'available_qty' => 2,
        'vendor' => 'MSMN',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p2->id,
        'product_uuid' => $p2->uuid,
        'selling_price' => '9.50',
        'currency' => 'CAD',
    ]);

    $p3 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000080003',
        'sku' => 'A-001',
        'barcode' => '333',
        'description' => 'Prod A1',
        'type' => 'HG',
        'available_qty' => null,
        'vendor' => 'MSMN',
    ]);

    // Should be excluded (no barcode).
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000080004',
        'sku' => 'Z-999',
        'barcode' => null,
        'description' => 'No barcode',
        'type' => 'ZZZ',
        'available_qty' => 1,
        'vendor' => 'Plamod',
    ]);

    $res = $this->get('/api/v1/products/export/barcoded');
    $res->assertOk();

    $csv = $res->streamedContent();

    $fh = fopen('php://temp', 'w+b');
    expect($fh)->not->toBeFalse();
    fwrite($fh, $csv);
    rewind($fh);

    $header = fgetcsv($fh);
    $header[0] = ltrim((string) ($header[0] ?? ''), "\xEF\xBB\xBF");
    expect($header)->toBe([
        'Handle',
        'Vendor',
        'SKU',
        'Type',
        'Product Name',
        'English name',
        'Available amount',
        'Selling price',
        'Quantity in store',
        'Difference',
        'Notes',
    ]);

    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $rows[] = $row;
    }
    fclose($fh);

    // Sorted by vendor then type then sku:
    // MSMN HG A-001, MSMN HG A-010, Plamod MG B-002
    expect($rows)->toHaveCount(3);
    expect($rows[0])->toBe(['', 'MSMN', 'A-001', 'HG', 'Prod A1', '', '', '', '', '', '']);
    expect($rows[1])->toBe(['', 'MSMN', 'A-010', 'HG', 'Prod A10', '', '2', '9.50', '', '', '']);
    expect($rows[2])->toBe(['', 'Plamod', 'B-002', 'MG', 'Prod B2', '', '5', '19.99', '', '', '']);
});


