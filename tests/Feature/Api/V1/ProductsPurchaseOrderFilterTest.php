<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('filters products by purchase order uuid', function (): void {
    $in = Product::query()->create([
        'sku' => 'PO-FILTER-IN',
        'description' => 'In PO',
    ]);
    $out = Product::query()->create([
        'sku' => 'PO-FILTER-OUT',
        'description' => 'Not in PO',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'ordered_date' => '2026-01-01',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $in->id,
        'sku' => $in->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 2,
    ]);

    $res = $this->getJson("/api/v1/products?per_page=100&purchase_order_uuid={$po->uuid}");
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'PO-FILTER-IN')
        ->assertJsonMissing(['sku' => 'PO-FILTER-OUT']);
});

it('rejects invalid purchase order uuid for products index', function (): void {
    $this->getJson('/api/v1/products?purchase_order_uuid=not-a-uuid')->assertStatus(422);
});

