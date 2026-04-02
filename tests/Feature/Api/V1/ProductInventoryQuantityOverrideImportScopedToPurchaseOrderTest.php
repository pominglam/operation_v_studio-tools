<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('overrides available qty scoped to a purchase order (missing => 0 only within the PO)', function (): void {
    Storage::fake('local');

    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000331111',
        'sku' => 'SKU-1',
        'barcode' => '111',
        'description' => 'P1',
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 9,
    ]);
    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000331222',
        'sku' => 'SKU-2',
        'barcode' => '222',
        'description' => 'P2',
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 5,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000333333',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'notes' => null,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p1->id,
        'sku' => 'SKU-1',
        'vendor' => 'Plamod',
        'unit_cost' => null,
        'vendor_unit_cost' => null,
        'qty_ordered' => 1,
        'qty_shipped' => null,
        'qty_received' => null,
    ]);

    // qty in 2nd column supported; blank means 1.
    $csv = "111,\n111,\n222,3\n";
    $file = UploadedFile::fake()->createWithContent('scan.csv', $csv);

    $res = $this->postJson('/api/v1/products/import-inventory-qty-override', [
        'file' => $file,
        'purchase_order_uuid' => $po->uuid,
    ]);

    $res->assertOk();
    $res->assertJsonPath('updated_products', 1);
    $res->assertJsonPath('reset_products', 1);
    $res->assertJsonPath('scoped_purchase_order_uuid', $po->uuid);

    $p1->refresh();
    $p2->refresh();

    // Only barcode 111 is in the PO scope (linked product). It appears twice => 2.
    expect($p1->available_qty)->toBe(2);
    // Not in the PO scope: should be untouched.
    expect($p2->available_qty)->toBe(5);
});

it('supports skip mode scoped to a purchase order (missing => untouched within the PO)', function (): void {
    Storage::fake('local');

    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000332111',
        'sku' => 'SKU-1',
        'barcode' => '111',
        'description' => 'P1',
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 9,
    ]);
    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000332222',
        'sku' => 'SKU-2',
        'barcode' => '222',
        'description' => 'P2',
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 5,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000333334',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'notes' => null,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p1->id,
        'sku' => 'SKU-1',
        'vendor' => 'Plamod',
        'unit_cost' => null,
        'vendor_unit_cost' => null,
        'qty_ordered' => 1,
        'qty_shipped' => null,
        'qty_received' => null,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p2->id,
        'sku' => 'SKU-2',
        'vendor' => 'Plamod',
        'unit_cost' => null,
        'vendor_unit_cost' => null,
        'qty_ordered' => 1,
        'qty_shipped' => null,
        'qty_received' => null,
    ]);

    // Only barcode 111 is present in the file. SKU-2 is in the PO scope but should remain unchanged in skip mode.
    $csv = "111,2\n";
    $file = UploadedFile::fake()->createWithContent('scan.csv', $csv);

    $res = $this->postJson('/api/v1/products/import-inventory-qty-override', [
        'file' => $file,
        'purchase_order_uuid' => $po->uuid,
        'missing_products_mode' => 'skip',
    ]);

    $res->assertOk();
    $res->assertJsonPath('updated_products', 1);
    $res->assertJsonPath('reset_products', 0);
    $res->assertJsonPath('scoped_purchase_order_uuid', $po->uuid);

    $p1->refresh();
    $p2->refresh();

    expect($p1->available_qty)->toBe(2);
    // In scope but not in file: should be untouched in skip mode.
    expect($p2->available_qty)->toBe(5);
});
