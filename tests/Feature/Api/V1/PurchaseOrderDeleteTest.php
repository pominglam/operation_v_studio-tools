<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('deletes an empty purchase order', function (): void {
    $po = PurchaseOrder::query()->create([
        'vendor' => 'Unknown',
        'shipping_total' => '0.00',
        'received_date' => now()->toDateString(),
        'notes' => 'Empty PO',
    ]);

    $this->deleteJson("/api/v1/purchase-orders/{$po->uuid}")
        ->assertOk()
        ->assertJsonPath('message', 'Purchase order deleted.');

    $this->assertDatabaseMissing('purchase_orders', ['id' => $po->id]);
});

it('returns 409 when deleting a purchase order that has items', function (): void {
    $product = Product::query()->create([
        'sku' => 'PO-DEL-SKU-1',
        'barcode' => null,
        'description' => 'Test',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'price' => '1.00',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'shipping_total' => '0.00',
        'received_date' => now()->toDateString(),
        'notes' => 'Non-empty PO',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => $po->vendor,
        'unit_cost' => '1.0000',
        'qty_received' => 0,
        'qty_ordered' => 1,
        'qty_shipped' => null,
    ]);

    $this->deleteJson("/api/v1/purchase-orders/{$po->uuid}")
        ->assertOk()
        ->assertJsonPath('message', 'Purchase order deleted.');

    $this->assertDatabaseMissing('purchase_orders', ['id' => $po->id]);
    $this->assertDatabaseMissing('purchase_order_items', ['purchase_order_id' => $po->id]);
});

it('returns 409 when deleting a purchase order that has inventory lots', function (): void {
    $product = Product::query()->create([
        'sku' => 'PO-DEL-SKU-2',
        'barcode' => null,
        'description' => 'Test',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'price' => '1.00',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'shipping_total' => '0.00',
        'received_date' => now()->toDateString(),
        'notes' => 'PO with lot',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => $po->vendor,
        'unit_cost' => '1.0000',
        'qty_received' => 1,
        'qty_ordered' => 1,
        'qty_shipped' => 1,
    ]);

    InventoryLot::query()->create([
        'product_id' => $product->id,
        'purchase_order_item_id' => $item->id,
        'source_type' => 'po',
        'unit_cost' => '1.0000',
        'shipping_per_unit' => null,
        'qty_received' => 1,
        'qty_remaining' => 1,
        'received_at' => now(),
    ]);

    $this->deleteJson("/api/v1/purchase-orders/{$po->uuid}")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Cannot delete a purchase order that has received inventory/lots. This would corrupt inventory history.');

    $this->assertDatabaseHas('purchase_orders', ['id' => $po->id]);
    $this->assertDatabaseHas('purchase_order_items', ['id' => $item->id]);
});
