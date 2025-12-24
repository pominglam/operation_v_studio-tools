<?php

declare(strict_types=1);

use App\Models\Product;

it('filters price research products by barcode set vs missing', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020001',
        'sku' => 'BC-SET',
        'barcode' => '1234567890123',
        'description' => 'Has barcode',
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020002',
        'sku' => 'BC-MISSING-NULL',
        'barcode' => null,
        'description' => 'Missing barcode null',
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020003',
        'sku' => 'BC-MISSING-EMPTY',
        'barcode' => '',
        'description' => 'Missing barcode empty',
    ]);

    $set = $this->getJson('/api/v1/price-research/products?per_page=50&barcode=set&sort_by=sku&sort_dir=asc');
    $set->assertOk();
    $setSkus = array_map(static fn (array $row): string => $row['sku'], $set->json('data') ?? []);
    expect($setSkus)->toEqual(['BC-SET']);

    $missing = $this->getJson('/api/v1/price-research/products?per_page=50&barcode=missing&sort_by=sku&sort_dir=asc');
    $missing->assertOk();
    $missingSkus = array_map(static fn (array $row): string => $row['sku'], $missing->json('data') ?? []);
    expect($missingSkus)->toEqual(['BC-MISSING-EMPTY', 'BC-MISSING-NULL']);
});


