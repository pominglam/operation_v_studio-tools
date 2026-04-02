<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('includes product_name on purchase order items', function (): void {
    $product = Product::query()->create([
        'sku' => 'PN-1',
        'barcode' => '123',
        'description' => 'My Product Name',
        'handle' => 'my-handle',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '1.0000',
        'qty_ordered' => 1,
    ]);

    $this->getJson("/api/v1/purchase-orders/{$po->uuid}")
        ->assertOk()
        ->assertJsonPath('data.items.0.product_name', 'My Product Name')
        ->assertJsonPath('data.items.0.product_barcode', '123')
        ->assertJsonPath('data.items.0.product_handle', 'my-handle');
});
