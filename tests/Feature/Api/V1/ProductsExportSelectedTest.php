<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalContent;
use App\Models\ProductSellingPrice;

it('exports selected products as barcoded CSV', function (): void {
    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090001',
        'sku' => 'EXP-SEL-1',
        'barcode' => '123',
        'description' => 'Export Selected 1',
        'handle' => 'export-selected-1',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'available_qty' => 2,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p1->id,
        'product_uuid' => $p1->uuid,
        'selling_price' => '10.00',
        'currency' => 'CAD',
    ]);

    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090002',
        'sku' => 'EXP-SEL-2',
        'barcode' => null,
        'description' => 'Export Selected 2',
        'handle' => null,
        'type' => 'HG',
        'vendor' => 'Plamod',
    ]);

    $res = $this->postJson('/api/v1/products/export/selected', [
        'export_type' => 'barcoded',
        'ids' => [$p1->uuid, $p2->uuid],
    ]);

    $res->assertOk();
    $content = $res->streamedContent();
    expect($content)->toContain('EXP-SEL-1')->not->toContain('EXP-SEL-2');
    expect($content)->toContain('Barcode');
    expect($content)->toContain(',123,');
});

it('exports selected products as Shopify CSV using available_qty as Variant Inventory Qty', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090010',
        'sku' => 'EXP-SEL-SHOPIFY-1',
        'barcode' => '123',
        'description' => 'Export Selected Shopify 1',
        'handle' => null,
        'type' => 'HG',
        'vendor' => 'Plamod',
        'filled_qty' => 2,
        'available_qty' => 7,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '10.00',
        'currency' => 'CAD',
    ]);

    $res = $this->postJson('/api/v1/products/export/selected', [
        'export_type' => 'shopify',
        'ids' => [$p->uuid],
    ]);

    $res->assertOk();

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    expect(count($lines))->toBe(2);

    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);

    /** @var array<string, int> $idx */
    $idx = array_flip($header);
    expect($row[$idx['Variant SKU']] ?? null)->toBe('EXP-SEL-SHOPIFY-1');
    expect($row[$idx['Variant Inventory Qty']] ?? null)->toBe('7');
});

it('exports selected products as Shopify CSV without inventory columns', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090011',
        'sku' => 'EXP-SEL-SHOPIFY-NO-INV-1',
        'barcode' => '321',
        'description' => 'Export Selected Shopify No Inventory 1',
        'handle' => null,
        'type' => 'HG',
        'vendor' => 'Plamod',
        'available_qty' => 42,
        'published_on_shopify' => true,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '15.00',
        'currency' => 'CAD',
    ]);
    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'description_html' => '<p>Selected body.</p><br><p>No extra break.</p>',
    ]);

    $res = $this->postJson('/api/v1/products/export/selected', [
        'export_type' => 'shopify_no_inventory',
        'ids' => [$p->uuid],
    ]);

    $res->assertOk();

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

    expect($row[$idx['Variant SKU']] ?? null)->toBe('EXP-SEL-SHOPIFY-NO-INV-1');
    expect($row[$idx['Body (HTML)']] ?? null)->toBe('<p>Selected body.</p><p>No extra break.</p>');
    expect($row[$idx['Price']] ?? null)->toBe('15.00');
    expect($row[$idx['Published']] ?? null)->toBe('TRUE');
    expect($row[$idx['Status']] ?? null)->toBe('active');
    expect(count($row))->toBe(count($header));
});

it('accepts selling_price sort_by for selected Shopify no-inventory export', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090012',
        'sku' => 'EXP-SEL-SHOPIFY-NO-INV-SORT',
        'description' => 'Export Selected Shopify sort',
        'type' => 'HG',
        'vendor' => 'Plamod',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '12.34',
        'currency' => 'CAD',
    ]);

    $res = $this->postJson('/api/v1/products/export/selected', [
        'export_type' => 'shopify_no_inventory',
        'ids' => [$p->uuid],
        'sort_by' => 'selling_price',
        'sort_dir' => 'asc',
    ]);

    $res->assertOk();
    expect($res->streamedContent())->toContain('EXP-SEL-SHOPIFY-NO-INV-SORT');
});

it('can include products missing selling price in Shopify selected export when requested', function (): void {
    $withPrice = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090020',
        'sku' => 'EXP-SEL-WITH-PRICE',
        'barcode' => '123',
        'description' => 'With price',
        'handle' => null,
        'type' => 'HG',
        'vendor' => 'Plamod',
        'available_qty' => 1,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $withPrice->id,
        'product_uuid' => $withPrice->uuid,
        'selling_price' => '10.00',
        'currency' => 'CAD',
    ]);

    $missingPrice = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090021',
        'sku' => 'EXP-SEL-NO-PRICE',
        'barcode' => '999',
        'description' => 'No price',
        'handle' => null,
        'type' => 'HG',
        'vendor' => 'Plamod',
        'available_qty' => 2,
    ]);

    $res = $this->postJson('/api/v1/products/export/selected', [
        'export_type' => 'shopify',
        'ids' => [$withPrice->uuid, $missingPrice->uuid],
        'include_missing_selling_price' => true,
    ]);
    $res->assertOk();

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];

    // Header + both products (even without selling price).
    expect(count($lines))->toBe(3);
    expect($csv)->toContain('EXP-SEL-WITH-PRICE');
    expect($csv)->toContain('EXP-SEL-NO-PRICE');
});

it('validates export_type for selected exports', function (): void {
    $this->postJson('/api/v1/products/export/selected', [
        'export_type' => 'nope',
        'ids' => ['00000000-0000-0000-0000-000000090001'],
    ])->assertStatus(422);
});

it('allows exporting more than 500 selected ids', function (): void {
    $ids = [];
    for ($i = 0; $i < 501; $i++) {
        $ids[] = sprintf('00000000-0000-0000-0000-%012d', $i + 1);
    }

    $res = $this->postJson('/api/v1/products/export/selected', [
        'export_type' => 'barcoded',
        'ids' => $ids,
    ]);

    $res->assertOk();
});