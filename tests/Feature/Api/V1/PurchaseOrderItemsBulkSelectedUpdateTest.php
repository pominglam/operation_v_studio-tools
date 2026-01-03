<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('bulk updates selected items qty_received', function (): void {
    $p1 = Product::query()->create(['sku' => 'BSEL-1', 'description' => 'Bulk Selected 1']);
    $p2 = Product::query()->create(['sku' => 'BSEL-2', 'description' => 'Bulk Selected 2']);

    $po = PurchaseOrder::query()->create(['vendor' => 'Dspiae']);

    $i1 = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p1->id,
        'sku' => $p1->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 5,
        'qty_shipped' => 5,
        'qty_received' => null,
    ]);
    $i2 = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p2->id,
        'sku' => $p2->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 5,
        'qty_shipped' => 5,
        'qty_received' => null,
    ]);

    $this->patchJson("/api/v1/purchase-orders/{$po->uuid}/items", [
        'ids' => [$i1->id],
        'changes' => [
            'qty_received' => 2,
        ],
    ])->assertOk();

    $this->assertDatabaseHas('purchase_order_items', ['id' => $i1->id, 'qty_received' => 2]);
    $this->assertDatabaseHas('purchase_order_items', ['id' => $i2->id, 'qty_received' => null]);
});

