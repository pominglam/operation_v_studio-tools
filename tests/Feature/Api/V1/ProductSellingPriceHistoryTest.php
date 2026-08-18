<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductSellingPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('records selling price history when PO set prices apply changes a price', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000222001',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'shipping_total' => '0.00',
        'received_date' => '2026-01-10',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000222002',
        'sku' => 'HIST-UPDATE-SKU',
        'barcode' => '9992221',
        'description' => 'History update product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '4.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'HIST-UPDATE-SKU',
        'vendor' => 'Plamod',
        'unit_cost' => '4.00',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/set-prices")
        ->assertOk()
        ->assertJsonPath('data.summary.updated', 1);

    $this->assertDatabaseHas('product_selling_price_history', [
        'product_id' => $p->id,
        'previous_price' => '4.99',
        'new_price' => '5.99',
        'source' => 'po_workflow',
        'purchase_order_id' => $po->id,
    ]);
});

it('does not record history when PO set prices leaves price unchanged', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000222011',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'shipping_total' => '0.00',
        'received_date' => '2026-01-10',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000222012',
        'sku' => 'HIST-UNCHANGED-SKU',
        'barcode' => '9992222',
        'description' => 'Already at formula',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '6.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'HIST-UNCHANGED-SKU',
        'vendor' => 'Plamod',
        'unit_cost' => '4.00',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/set-prices")
        ->assertOk()
        ->assertJsonPath('data.summary.updated', 0);

    $this->assertDatabaseMissing('product_selling_price_history', [
        'product_id' => $p->id,
    ]);
});

it('records manual selling price history without a purchase order link', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000222021',
        'sku' => 'HIST-MANUAL-SKU',
        'barcode' => '9992223',
        'description' => 'Manual edit product',
        'type' => 'Others',
        'vendor' => 'Plamod',
    ]);

    $this->putJson("/api/v1/products/{$p->uuid}/selling-price", [
        'selling_price' => 12.99,
        'currency' => 'CAD',
    ])->assertOk();

    $this->assertDatabaseHas('product_selling_price_history', [
        'product_id' => $p->id,
        'previous_price' => null,
        'new_price' => '12.99',
        'source' => 'manual',
        'purchase_order_id' => null,
    ]);
});

it('lists selling price history entries for a purchase order', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000222031',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'shipping_total' => '0.00',
        'received_date' => '2026-01-10',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000222032',
        'sku' => 'HIST-LIST-SKU',
        'barcode' => '9992224',
        'description' => 'Listed history product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'HIST-LIST-SKU',
        'vendor' => 'Plamod',
        'unit_cost' => '4.00',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/set-prices")
        ->assertOk();

    $res = $this->getJson("/api/v1/purchase-orders/{$po->uuid}/selling-price-history");

    $res->assertOk();
    $res->assertJsonPath('data.entries.0.sku', 'HIST-LIST-SKU');
    $res->assertJsonPath('data.entries.0.previous_price', null);
    $res->assertJsonPath('data.entries.0.new_price', '5.99');
    $res->assertJsonPath('data.entries.0.source', 'po_workflow');
});

it('lists product selling price history with optional purchase order uuid', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000222041',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'shipping_total' => '0.00',
        'received_date' => '2026-01-10',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000222042',
        'sku' => 'HIST-PRODUCT-SKU',
        'barcode' => '9992225',
        'description' => 'Product history listing',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'HIST-PRODUCT-SKU',
        'vendor' => 'Plamod',
        'unit_cost' => '4.00',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/set-prices")
        ->assertOk();

    $this->putJson("/api/v1/products/{$p->uuid}/selling-price", [
        'selling_price' => 9.99,
    ])->assertOk();

    $res = $this->getJson("/api/v1/products/{$p->uuid}/selling-price-history");

    $res->assertOk();
    $res->assertJsonCount(2, 'data.entries');
    $res->assertJsonPath('data.entries.0.new_price', '9.99');
    $res->assertJsonPath('data.entries.0.source', 'manual');
    $res->assertJsonPath('data.entries.0.purchase_order_uuid', null);
    $res->assertJsonPath('data.entries.1.new_price', '5.99');
    $res->assertJsonPath('data.entries.1.purchase_order_uuid', $po->uuid);
});
