<?php

declare(strict_types=1);

use App\Models\Product;
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
});

it('validates export_type for selected exports', function (): void {
    $this->postJson('/api/v1/products/export/selected', [
        'export_type' => 'nope',
        'ids' => ['00000000-0000-0000-0000-000000090001'],
    ])->assertStatus(422);
});

