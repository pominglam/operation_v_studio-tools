<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('filters price research products by purchase order uuid', function (): void {
    $in = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020001',
        'sku' => 'PR-PO-IN',
        'description' => 'In PO',
    ]);
    $out = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020002',
        'sku' => 'PR-PO-OUT',
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
        'qty_ordered' => 1,
    ]);

    $res = $this->getJson("/api/v1/price-research/products?per_page=100&purchase_order_uuid={$po->uuid}");
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'PR-PO-IN')
        ->assertJsonMissing(['sku' => 'PR-PO-OUT']);
});

it('rejects invalid purchase order uuid for price research products', function (): void {
    $this->getJson('/api/v1/price-research/products?purchase_order_uuid=not-a-uuid')->assertStatus(422);
});

