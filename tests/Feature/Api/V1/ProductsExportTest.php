<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalContent;
use App\Models\ProductSellingPrice;

it('exports Shopify CSV for products with selling price only', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000001',
        'sku' => 'ABC123',
        'barcode' => '0123456789012',
        'description' => 'HG 1/144 DEMI BARDING',
        'type' => 'HG',
        'price' => '10.00',
        'published_on_shopify' => false,
        'latest_arrival' => true,
        'order_qty' => 5,
        'filled_qty' => 2,
        'available_qty' => 2,
        'extended' => '20.00',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '28.99',
        'currency' => 'CAD',
    ]);
    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'description_html' => '<p>Line one.</p><br><p>Line two.</p>',
    ]);

    Product::query()->create([
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
    $res->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];

    // Header + ONLY products with selling price (ABC123)
    expect(count($lines))->toBe(2);
    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);

    expect($header)->toEqual([
        'Handle',
        'Title',
        'Body (HTML)',
        'Vendor',
        'Product Category',
        'Type',
        'Tags',
        'Published',
        'Published Scope',
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
        'Price',
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
    expect($row[2])->toBe('<p>Line one.</p><p>Line two.</p>');
    expect($row[5])->toBe('HG');
    expect($row[6])->toBe('model kit, HG, latest arrival');
    expect($row[7])->toBe('FALSE');
    expect($row[8])->toBe('global');
    expect($row[18])->toBe('ABC123');
    expect($row[21])->toBe('2');
    expect($row[22])->toBe('deny');
    expect($row[23])->toBe('manual');
    expect($row[24])->toBe('28.99');
    expect($row[32])->toBe('0123456789012');
    expect($row[43])->toBe('draft');
});

it('exports Shopify CSV without inventory columns when format=shopify_no_inventory', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000006',
        'sku' => 'NO-INV-1',
        'barcode' => '123456',
        'description' => 'No inventory export',
        'type' => 'HG',
        'available_qty' => 11,
        'published_on_shopify' => true,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '19.99',
        'currency' => 'CAD',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify_no_inventory');
    $res->assertOk();
    $res->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    expect(count($lines))->toBe(2);

    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);
    /** @var array<string, int> $idx */
    $idx = array_flip($header);

    expect($header)->not->toContain('Variant Inventory Tracker');
    expect($header)->not->toContain('Variant Inventory Qty');
    expect($header)->not->toContain('Variant Inventory Policy');

    expect($row[$idx['Variant SKU']] ?? null)->toBe('NO-INV-1');
    expect($row[$idx['Price']] ?? null)->toBe('19.99');
    expect($row[$idx['Published']] ?? null)->toBe('TRUE');
    expect($row[$idx['Status']] ?? null)->toBe('active');
    expect(count($row))->toBe(count($header));
});

it('accepts selling_price sort_by for full Shopify no-inventory export', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000007',
        'sku' => 'NO-INV-SORT-1',
        'description' => 'No inventory export sort',
        'type' => 'HG',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '33.33',
        'currency' => 'CAD',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify_no_inventory&sort_by=selling_price&sort_dir=asc');
    $res->assertOk();
    expect($res->streamedContent())->toContain('NO-INV-SORT-1');
});

it('accepts reorder sort_by for full Shopify no-inventory export', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000008',
        'sku' => 'NO-INV-REORDER-1',
        'description' => 'No inventory export reorder sort',
        'type' => 'HG',
        'available_qty' => 0,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '22.22',
        'currency' => 'CAD',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify_no_inventory&sort_by=reorder&sort_dir=desc');
    $res->assertOk();
    expect($res->streamedContent())->toContain('NO-INV-REORDER-1');
});

it('exports empty Tags when main_type is blank', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000005',
        'sku' => 'NO-TAGS-1',
        'barcode' => '777',
        'description' => 'No tags product',
        'main_type' => '',
        'type' => 'HG',
        'latest_arrival' => true,
        'published_on_shopify' => false,
        'available_qty' => 1,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '9.99',
        'currency' => 'CAD',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify');
    $res->assertOk();

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    expect(count($lines))->toBeGreaterThanOrEqual(2);

    $rows = array_map('str_getcsv', $lines);
    $row = collect($rows)->first(function (array $r): bool {
        return ($r[18] ?? '') === 'NO-TAGS-1';
    });

    expect($row)->not->toBeNull();
    /** @var array<int, string> $row */
    expect($row[6])->toBe(''); // Tags
});

it('exports archived products with Status=archived', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000099',
        'sku' => 'ARCH-SKU-1',
        'barcode' => '888',
        'description' => 'Archived product',
        'type' => 'HG',
        'archived_at' => now(),
        'available_qty' => 0,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '9.99',
        'currency' => 'CAD',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify');
    $res->assertOk();

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    expect(count($lines))->toBeGreaterThanOrEqual(2);

    $rows = array_map('str_getcsv', $lines);
    $row = collect($rows)->first(function (array $r): bool {
        return ($r[18] ?? '') === 'ARCH-SKU-1';
    });

    expect($row)->not->toBeNull();
    /** @var array<int, string> $row */
    expect($row[7])->toBe('FALSE'); // Published
    expect($row[43])->toBe('archived'); // Status
});

it('exports Published=TRUE when product is published_on_shopify', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000003',
        'sku' => 'PUB-1',
        'barcode' => '999',
        'description' => 'Published product',
        'type' => 'HG',
        'published_on_shopify' => true,
        'filled_qty' => 1,
        'available_qty' => 1,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '9.99',
        'currency' => 'CAD',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify');
    $res->assertOk();

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    expect(count($lines))->toBeGreaterThanOrEqual(2);

    $rows = array_map('str_getcsv', $lines);
    $publishedRow = collect($rows)->first(function (array $r): bool {
        return ($r[18] ?? '') === 'PUB-1';
    });

    expect($publishedRow)->not->toBeNull();
    /** @var array<int, string> $publishedRow */
    expect($publishedRow[7])->toBe('TRUE');
    expect($publishedRow[8])->toBe('global');
});

it('uses stored handle when exporting Shopify CSV', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000004',
        'sku' => 'HANDLE-1',
        'barcode' => '111',
        'description' => 'Something with a handle',
        'handle' => 'rg-1144-ms-06s-zakuii',
        'type' => 'RG',
        'published_on_shopify' => false,
        'filled_qty' => 1,
        'available_qty' => 1,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '9.99',
        'currency' => 'CAD',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify');
    $res->assertOk();

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    expect(count($lines))->toBeGreaterThanOrEqual(2);

    $rows = array_map('str_getcsv', $lines);
    $row = collect($rows)->first(function (array $r): bool {
        return ($r[18] ?? '') === 'HANDLE-1';
    });

    expect($row)->not->toBeNull();
    /** @var array<int, string> $row */
    expect($row[0])->toBe('rg-1144-ms-06s-zakuii');
});

it('lists products missing selling price for export UI', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000010',
        'sku' => 'MISS-1',
        'barcode' => null,
        'description' => 'Missing price 1',
        'type' => 'HG',
    ]);

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000011',
        'sku' => 'HAVE-1',
        'barcode' => '111',
        'description' => 'Has price',
        'type' => 'HG',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => Product::query()->where('uuid', '00000000-0000-0000-0000-000000000011')->value('id'),
        'product_uuid' => '00000000-0000-0000-0000-000000000011',
        'selling_price' => '1.23',
        'currency' => 'CAD',
    ]);

    $res = $this->getJson('/api/v1/products/export/missing-selling-price?format=shopify');
    $res->assertOk()
        ->assertJsonPath('data.0.id', $p->uuid)
        ->assertJsonPath('data.0.sku', 'MISS-1');
});

it('exports products missing barcode as CSV', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000020',
        'sku' => 'NOBC-1',
        'barcode' => null,
        'description' => 'No barcode 1',
    ]);

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000021',
        'sku' => 'HASBC-1',
        'barcode' => '123',
        'description' => 'Has barcode',
    ]);

    $res = $this->get('/api/v1/products/export/missing-barcode?format=shopify');
    $res->assertOk();
    $res->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];

    // Header + only the missing-barcode row
    expect(count($lines))->toBe(2);
    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);

    expect($header)->toEqual(['Variant SKU', 'Title', 'Variant Barcode']);
    expect($row[0])->toBe('NOBC-1');
    expect($row[1])->toBe('No barcode 1');
    expect($row[2])->toBe('');
});

it('rejects unknown export format', function (): void {
    $res = $this->getJson('/api/v1/products/export?format=unknown');
    $res->assertStatus(422);
});
