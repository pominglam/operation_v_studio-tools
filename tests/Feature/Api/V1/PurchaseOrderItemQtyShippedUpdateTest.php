<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('updates qty_shipped for a purchase order item', function (): void {
    $product = Product::query()->create([
        'sku' => 'SHIP-1',
        'description' => 'Ship 1',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 2,
        'qty_shipped' => null,
    ]);

    $res = $this->patchJson("/api/v1/purchase-order-items/{$item->id}", [
        'qty_shipped' => 1,
    ]);

    $res->assertOk()->assertJsonPath('data.qty_shipped', 1);
    $this->assertDatabaseHas('purchase_order_items', ['id' => $item->id, 'qty_shipped' => 1]);
});

it('updates qty_ordered for a purchase order item', function (): void {
    $product = Product::query()->create([
        'sku' => 'ORD-1',
        'description' => 'Ordered 1',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => null,
        'qty_shipped' => null,
        'qty_received' => null,
    ]);

    $res = $this->patchJson("/api/v1/purchase-order-items/{$item->id}", [
        'qty_ordered' => 7,
    ]);

    $res->assertOk()->assertJsonPath('data.qty_ordered', 7);
    $this->assertDatabaseHas('purchase_order_items', ['id' => $item->id, 'qty_ordered' => 7]);
});

it('updates unit_cost for a purchase order item', function (): void {
    $product = Product::query()->create([
        'sku' => 'COST-1',
        'description' => 'Cost 1',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 1,
    ]);

    $res = $this->patchJson("/api/v1/purchase-order-items/{$item->id}", [
        'unit_cost' => 2.34,
    ]);

    $res->assertOk()->assertJsonPath('data.unit_cost', '2.34');
    $this->assertDatabaseHas('purchase_order_items', ['id' => $item->id, 'unit_cost' => '2.3400']);
});

it('updates vendor_unit_cost when editing unit_cost on foreign-currency PO item', function (): void {
    $product = Product::query()->create([
        'sku' => 'COST-FX-1',
        'description' => 'Cost FX 1',
        'vendor' => 'Stedi',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'HKD',
        'fx_rate_to_cad' => '0.200000',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Stedi',
        'unit_cost' => '2.0000',
        'vendor_unit_cost' => '10.0000',
        'qty_ordered' => 1,
    ]);

    $res = $this->patchJson("/api/v1/purchase-order-items/{$item->id}", [
        'unit_cost' => 3.00,
    ]);

    $res->assertOk()->assertJsonPath('data.unit_cost', '3.00');
    $this->assertDatabaseHas('purchase_order_items', [
        'id' => $item->id,
        'unit_cost' => '3.0000',
        'vendor_unit_cost' => '15.0000',
    ]);
});

it('rejects negative unit_cost for a purchase order item', function (): void {
    $product = Product::query()->create([
        'sku' => 'COST-NEG-1',
        'description' => 'Cost negative',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 1,
    ]);

    $this->patchJson("/api/v1/purchase-order-items/{$item->id}", [
        'unit_cost' => -0.01,
    ])->assertStatus(422)->assertJsonValidationErrors(['unit_cost']);
});

it('blocks qty_ordered lower than qty_shipped/qty_received', function (): void {
    $product = Product::query()->create([
        'sku' => 'ORD-2',
        'description' => 'Ordered 2',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 10,
        'qty_shipped' => 6,
        'qty_received' => 4,
    ]);

    $this->patchJson("/api/v1/purchase-order-items/{$item->id}", [
        'qty_ordered' => 3,
    ])->assertStatus(422)->assertJsonPath('issues.0.kind', 'qty_shipped_exceeds_ordered');
});

it('blocks qty_shipped greater than qty_ordered', function (): void {
    $product = Product::query()->create([
        'sku' => 'SHIP-2',
        'description' => 'Ship 2',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 2,
    ]);

    $this->patchJson("/api/v1/purchase-order-items/{$item->id}", [
        'qty_shipped' => 3,
    ])->assertStatus(422)->assertJsonPath('issues.0.kind', 'qty_shipped_exceeds_ordered');
});

it('bulk sets qty_shipped = qty_ordered for all items in a PO', function (): void {
    $p1 = Product::query()->create(['sku' => 'SHIP-B-1', 'description' => 'B1']);
    $p2 = Product::query()->create(['sku' => 'SHIP-B-2', 'description' => 'B2']);

    $po = PurchaseOrder::query()->create(['vendor' => 'Dspiae']);

    $i1 = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p1->id,
        'sku' => $p1->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 2,
        'qty_shipped' => null,
    ]);
    $i2 = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p2->id,
        'sku' => $p2->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 5,
        'qty_shipped' => 1,
    ]);

    $res = $this->patchJson("/api/v1/purchase-orders/{$po->uuid}/items", [
        'set_all_to_ordered' => true,
    ]);

    $res->assertOk();
    $this->assertDatabaseHas('purchase_order_items', ['id' => $i1->id, 'qty_shipped' => 2]);
    $this->assertDatabaseHas('purchase_order_items', ['id' => $i2->id, 'qty_shipped' => 5]);
});

it('updates qty_received for a purchase order item', function (): void {
    $product = Product::query()->create([
        'sku' => 'REC-1',
        'description' => 'Received 1',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 5,
        'qty_shipped' => 5,
        'qty_received' => null,
    ]);

    $this->patchJson("/api/v1/purchase-order-items/{$item->id}", [
        'qty_received' => 3,
    ])->assertOk()->assertJsonPath('data.qty_received', 3);

    $this->assertDatabaseHas('purchase_order_items', ['id' => $item->id, 'qty_received' => 3]);
});

it('blocks qty_received when lots exist for the item', function (): void {
    $product = Product::query()->create([
        'sku' => 'REC-LOTS-1',
        'description' => 'Received lots',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 5,
        'qty_shipped' => 5,
        'qty_received' => 1,
    ]);

    InventoryLot::query()->create([
        'product_id' => $product->id,
        'purchase_order_item_id' => $item->id,
        'source_type' => 'po',
        'unit_cost' => '1.0000',
        'shipping_per_unit' => '0.100000',
        'qty_received' => 1,
        'qty_remaining' => 1,
        'received_at' => now(),
    ]);

    $this->patchJson("/api/v1/purchase-order-items/{$item->id}", [
        'qty_received' => 2,
    ])->assertStatus(409)->assertJsonPath('issues.0.kind', 'qty_received_has_lots');
});
