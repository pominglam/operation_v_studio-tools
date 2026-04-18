<?php

declare(strict_types=1);

use App\Models\Product;

it('sorts price research products by shipped (filled) qty', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000010001',
        'sku' => 'SORT-F-1',
        'barcode' => null,
        'description' => 'Sort filled 1',
        'filled_qty' => 1,
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000010002',
        'sku' => 'SORT-F-10',
        'barcode' => null,
        'description' => 'Sort filled 10',
        'filled_qty' => 10,
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000010003',
        'sku' => 'SORT-F-0',
        'barcode' => null,
        'description' => 'Sort filled 0',
        'filled_qty' => 0,
    ]);

    $asc = $this->getJson('/api/v1/price-research/products?per_page=10&sort_by=filled&sort_dir=asc');
    $asc->assertOk();

    $ascSkus = array_map(static fn (array $row): string => $row['sku'], $asc->json('data') ?? []);
    expect(array_slice($ascSkus, 0, 3))->toEqual(['SORT-F-0', 'SORT-F-1', 'SORT-F-10']);

    $desc = $this->getJson('/api/v1/price-research/products?per_page=10&sort_by=filled&sort_dir=desc');
    $desc->assertOk();

    $descSkus = array_map(static fn (array $row): string => $row['sku'], $desc->json('data') ?? []);
    expect(array_slice($descSkus, 0, 3))->toEqual(['SORT-F-10', 'SORT-F-1', 'SORT-F-0']);
});
