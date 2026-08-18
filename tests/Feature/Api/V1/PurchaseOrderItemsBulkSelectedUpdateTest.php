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

it('bulk updates selected items product vendor on linked catalog products', function (): void {
    $p1 = Product::query()->create(['sku' => 'BVEND-1', 'description' => 'Bulk vendor 1', 'vendor' => null]);
    $p2 = Product::query()->create(['sku' => 'BVEND-2', 'description' => 'Bulk vendor 2', 'vendor' => 'Dspiae']);

    $po = PurchaseOrder::query()->create(['vendor' => 'Other/multi']);

    $i1 = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p1->id,
        'sku' => $p1->sku,
        'vendor' => 'Other/multi',
        'unit_cost' => '1.0000',
        'qty_ordered' => 1,
    ]);
    $i2 = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p2->id,
        'sku' => $p2->sku,
        'vendor' => 'Other/multi',
        'unit_cost' => '1.0000',
        'qty_ordered' => 1,
    ]);

    $this->patchJson("/api/v1/purchase-orders/{$po->uuid}/items", [
        'ids' => [$i1->id, $i2->id],
        'changes' => [
            'product_vendor' => 'Stedi',
        ],
    ])->assertOk()
        ->assertJsonPath('data.counts.unassigned_product_vendor', 0);

    $p1->refresh();
    $p2->refresh();

    expect($p1->vendor)->toBe('Stedi')
        ->and($p2->vendor)->toBe('Stedi');
});
