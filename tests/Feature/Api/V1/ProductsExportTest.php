<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductSellingPrice;

it('exports Shopify CSV with expected headers and key fields', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000001',
        'sku' => 'ABC123',
        'barcode' => '0123456789012',
        'description' => 'HG 1/144 DEMI BARDING',
        'type' => 'HG',
        'price' => '10.00',
        'order_qty' => 5,
        'filled_qty' => 2,
        'extended' => '20.00',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '28.99',
        'currency' => 'CAD',
    ]);

    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000002',
        'sku' => 'DEF456',
        'barcode' => null,
        'description' => 'HG 1/144 SOME OTHER KIT',
        'type' => 'HG',
        'price' => '10.00',
        'order_qty' => 1,
        'filled_qty' => 1,
        'extended' => '10.00',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify');
    $res->assertOk();

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    expect(count($lines))->toBe(Product::query()->count() + 1);
    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);
    $row2 = str_getcsv($lines[2]);

    expect($header)->toEqual([
        'Handle',
        'Title',
        'Body (HTML)',
        'Vendor',
        'Product Category',
        'Type',
        'Tags',
        'Published',
        'Option1 Name',
        'Option1 Value',
        'Option1 Linked To',
        'Option2 Name',
        'Option2 Value',
        'Option2 Linked To',
        'Option3 Name',
        'Option3 Value',
        'Option3 Linked To',
        'Variant SKU',
        'Variant Grams',
        'Variant Inventory Tracker',
        'Variant Inventory Qty',
        'Variant Inventory Policy',
        'Variant Fulfillment Service',
        'Variant Price',
        'Variant Compare At Price',
        'Variant Requires Shipping',
        'Variant Taxable',
        'Unit Price Total Measure',
        'Unit Price Total Measure Unit',
        'Unit Price Base Measure',
        'Unit Price Base Measure Unit',
        'Variant Barcode',
        'Image Src',
        'Image Position',
        'Image Alt Text',
        'Gift Card',
        'SEO Title',
        'SEO Description',
        'Variant Image',
        'Variant Weight Unit',
        'Variant Tax Code',
        'Cost per item',
        'Status',
    ]);

    // Row contains handle + title + sku + qty + policy + price + barcode + status.
    expect($row[0])->toBe('hg-1144-demi-barding');
    expect($row[1])->toBe('HG 1/144 DEMI BARDING');
    expect($row[5])->toBe('HG');
    expect($row[6])->toBe('HG');
    expect($row[17])->toBe('ABC123');
    expect($row[20])->toBe('2');
    expect($row[21])->toBe('continue');
    expect($row[22])->toBe('manual');
    expect($row[23])->toBe('28.99');
    expect($row[31])->toBe('0123456789012');
    expect($row[42])->toBe('active');

    // When selling price is missing, fall back to 1.5x unit cost.
    expect($row2[17])->toBe('DEF456');
    expect($row2[23])->toBe('15.00');
});

it('rejects unknown export format', function (): void {
    $res = $this->getJson('/api/v1/products/export?format=unknown');
    $res->assertStatus(422);
});
