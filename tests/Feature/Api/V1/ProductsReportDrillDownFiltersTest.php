<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('filters products with not_arrived_min for report drill-down', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150001',
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
        'ordered_date' => '2026-05-20',
    ]);

    $withInbound = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150011',
        'sku' => 'DRILL-NA-1',
        'description' => 'Inbound product',
        'vendor' => 'Stedi',
        'main_type' => 'model kit',
    ]);
    $withoutInbound = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150012',
        'sku' => 'DRILL-NA-0',
        'description' => 'No inbound product',
        'vendor' => 'Stedi',
        'main_type' => 'model kit',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $withInbound->id,
        'sku' => 'DRILL-NA-1',
        'vendor' => 'Stedi',
        'qty_ordered' => 3,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=50&main_types[]=model+kit&not_arrived_min=1&search=DRILL-NA-');

    $res->assertOk();
    $skus = collect($res->json('data'))->pluck('sku')->all();
    expect($skus)->toContain('DRILL-NA-1')->not->toContain('DRILL-NA-0');
});

it('filters on-hand products missing latest landed cost for report drill-down', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150021',
        'sku' => 'DRILL-MISS-1',
        'description' => 'Missing landed',
        'vendor' => 'Stedi',
        'main_type' => 'tools',
        'available_qty' => 2,
        'latest_landed_unit_cost' => null,
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150022',
        'sku' => 'DRILL-MISS-2',
        'description' => 'Has landed',
        'vendor' => 'Stedi',
        'main_type' => 'tools',
        'available_qty' => 1,
        'latest_landed_unit_cost' => '12.50',
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150023',
        'sku' => 'DRILL-MISS-3',
        'description' => 'Zero on hand missing landed',
        'vendor' => 'Stedi',
        'main_type' => 'tools',
        'available_qty' => 0,
        'latest_landed_unit_cost' => null,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=50&main_types[]=tools&missing_landed_cost=1&search=DRILL-MISS-');

    $res->assertOk();
    $skus = collect($res->json('data'))->pluck('sku')->all();
    expect($skus)->toContain('DRILL-MISS-1')->not->toContain('DRILL-MISS-2', 'DRILL-MISS-3');
});

it('filters products with has_landed_cost for report drill-down', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150031',
        'sku' => 'DRILL-HAS-1',
        'description' => 'Has landed',
        'vendor' => 'Stedi',
        'main_type' => 'paint',
        'available_qty' => 4,
        'latest_landed_unit_cost' => '8.00',
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150032',
        'sku' => 'DRILL-HAS-2',
        'description' => 'Missing landed',
        'vendor' => 'Stedi',
        'main_type' => 'paint',
        'available_qty' => 2,
        'latest_landed_unit_cost' => null,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=50&main_types[]=paint&available_min=1&has_landed_cost=1&search=DRILL-HAS-');

    $res->assertOk();
    $skus = collect($res->json('data'))->pluck('sku')->all();
    expect($skus)->toContain('DRILL-HAS-1')->not->toContain('DRILL-HAS-2');
});
