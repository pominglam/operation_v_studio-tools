<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('adds qty received from PO lines to product available qty by exact linked product', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120001',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $a = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120011',
        'sku' => 'PO-ADD-A',
        'description' => 'Product A',
        'vendor' => 'Plamod',
        'available_qty' => 10,
    ]);
    $b = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120012',
        'sku' => 'PO-ADD-B',
        'description' => 'Product B',
        'vendor' => 'Plamod',
        'available_qty' => null,
    ]);
    $c = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120013',
        'sku' => 'PO-ADD-C',
        'description' => 'Product C',
        'vendor' => 'Plamod',
        'available_qty' => 7,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $a->id,
        'sku' => 'PO-ADD-A',
        'vendor' => 'Plamod',
        'qty_ordered' => 5,
        'qty_received' => 2,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $a->id,
        'sku' => 'PO-ADD-A',
        'vendor' => 'Plamod',
        'qty_ordered' => 3,
        'qty_received' => 3,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $b->id,
        'sku' => 'PO-ADD-B',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
        'qty_received' => 1,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $b->id,
        'sku' => 'PO-ADD-B',
        'vendor' => 'Plamod',
        'qty_ordered' => 2,
        'qty_received' => 0,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/apply-received-to-available");
    $res->assertOk();
    $res->assertJsonPath('ok', true);
    $res->assertJsonPath('data.apply.products_updated', 2);
    $res->assertJsonPath('data.apply.total_added', 6);
    $res->assertJsonPath('data.apply.lines_considered', 4);
    $res->assertJsonPath('data.apply.skipped_non_positive_qty', 1);
    $res->assertJsonPath('data.apply.skipped_missing_product_id', 0);

    $a->refresh();
    $b->refresh();
    $c->refresh();
    expect($a->available_qty)->toBe(15);
    expect($b->available_qty)->toBe(1);
    expect($c->available_qty)->toBe(7);
});
