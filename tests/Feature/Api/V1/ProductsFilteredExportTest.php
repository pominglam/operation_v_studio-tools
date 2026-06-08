<?php

declare(strict_types=1);

use App\Models\ProductSellingPrice;

it('exports all products matching current list filters', function (): void {
    $inRange = \App\Models\Product::query()->create([
        'sku' => 'FILTER-EXP-IN',
        'description' => 'In range export',
        'vendor' => 'Plamod',
        'available_qty' => 8,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $inRange->id,
        'product_uuid' => $inRange->uuid,
        'selling_price' => '12.50',
    ]);

    \App\Models\Product::query()->create([
        'sku' => 'FILTER-EXP-OUT',
        'description' => 'Out of range export',
        'vendor' => 'Plamod',
        'available_qty' => 2,
    ]);

    $res = $this->get('/api/v1/products/export/filtered?available_min=5&available_max=10&sort_by=sku&sort_dir=asc');
    $res->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $res->streamedContent();
    expect($csv)->toContain('FILTER-EXP-IN')
        ->and($csv)->toContain('12.50')
        ->and($csv)->not->toContain('FILTER-EXP-OUT');
});
