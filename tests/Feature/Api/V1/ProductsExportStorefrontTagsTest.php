<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductSellingPrice;

it('exports pilot storefront tags for masking tape in shopify CSV', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000201',
        'sku' => 'MT-10',
        'barcode' => '901',
        'description' => 'Masking tape 10mm',
        'main_type' => 'supplies',
        'type' => 'Others',
        'published_on_shopify' => false,
        'available_qty' => 5,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '4.99',
        'currency' => 'CAD',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify');
    $res->assertOk();

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    $rows = array_map('str_getcsv', $lines);
    $row = collect($rows)->first(function (array $r): bool {
        return ($r[18] ?? '') === 'MT-10';
    });

    expect($row)->not->toBeNull();
    /** @var array<int, string> $row */
    expect($row[6])->not->toContain('supplies')
        ->and($row[6])->not->toContain('Others')
        ->and($row[6])->toContain('ts:dept:tapes')
        ->and($row[6])->toContain('ts:tape:masking')
        ->and($row[6])->toContain('ts:tape:width:10');
});

it('exports pilot storefront tags for decal softener in shopify CSV', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000202',
        'sku' => 'ETC-04',
        'barcode' => '902',
        'description' => 'Decal setting solution',
        'main_type' => 'supplies',
        'type' => 'Others',
        'published_on_shopify' => false,
        'available_qty' => 3,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '6.99',
        'currency' => 'CAD',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify');
    $res->assertOk();

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    $rows = array_map('str_getcsv', $lines);
    $row = collect($rows)->first(function (array $r): bool {
        return ($r[18] ?? '') === 'ETC-04';
    });

    expect($row)->not->toBeNull();
    /** @var array<int, string> $row */
    expect($row[6])->toContain('ts:dept:decals')
        ->and($row[6])->toContain('ts:decal:softener');
});

it('adds storefront paint tags to paint SKUs in shopify CSV', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000203',
        'sku' => 'XG-100',
        'barcode' => '903',
        'description' => 'Solid paint',
        'main_type' => 'supplies',
        'type' => 'PAINT',
        'published_on_shopify' => false,
        'available_qty' => 2,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '5.99',
        'currency' => 'CAD',
    ]);

    $res = $this->get('/api/v1/products/export?format=shopify');
    $res->assertOk();

    $csv = $res->streamedContent();
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    $rows = array_map('str_getcsv', $lines);
    $row = collect($rows)->first(function (array $r): bool {
        return ($r[18] ?? '') === 'XG-100';
    });

    expect($row)->not->toBeNull();
    /** @var array<int, string> $row */
    expect($row[6])->toContain('ts:dept:paints', 'ts:paint:product:paint');
});
