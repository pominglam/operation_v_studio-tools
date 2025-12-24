<?php

declare(strict_types=1);

use App\Models\Product;

it('includes available qty on price research products payload and can sort by available', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050001',
        'sku' => 'AVAIL-1',
        'barcode' => null,
        'description' => 'Avail 1',
        'available_qty' => 1,
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050002',
        'sku' => 'AVAIL-10',
        'barcode' => null,
        'description' => 'Avail 10',
        'available_qty' => 10,
    ]);

    $res = $this->getJson('/api/v1/price-research/products?per_page=10&sort_by=available&sort_dir=asc');
    $res->assertOk();

    $data = $res->json('data') ?? [];
    expect($data)->toBeArray();

    $skus = array_map(static fn (array $row): string => $row['sku'], $data);
    expect(array_slice($skus, 0, 2))->toEqual(['AVAIL-1', 'AVAIL-10']);

    $first = $data[0] ?? [];
    expect($first)->toHaveKey('available');
    expect($first['available'])->toBe(1);
});


