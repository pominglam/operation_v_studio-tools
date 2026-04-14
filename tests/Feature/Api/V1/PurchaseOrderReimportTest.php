<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;

it('reimports into an existing PO: updates product barcode and replaces PO items', function (): void {
    $product = Product::query()->create([
        'sku' => 'PT-AB',
        'barcode' => '697715E+12', // bad/scientific-ish stored value
        'description' => 'Old name',
        'type' => 'Others',
        'vendor' => 'Dspiae',
        'price' => '1.00',
        'available_qty' => 0,
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
        'vendor_currency_code' => 'HKD',
        'vendor_product_total' => '1292.00',
        'product_total' => '300.00',
        'fx_rate_to_cad' => '0.232198',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '64.6000',
        'qty_ordered' => 1,
    ]);

    $csv = implode("\n", [
        'DSPIAE,,,,,,,,,,',
        'Total Weight/g,,,,,2100,Price/HKD,,,,1292.00',
        'Product,SKU,Product name,Barcode,Weight/g,Wholesale price,Recommended Retail Price,Each carton contain,Required Quantity / pcs (Carton Multiple),Total weight/g,Total Amount for Single',
        ',PT-AB,Wash-Free airbrush,6977151546258,105, HK$64.60 , HK$76.00 ,20,2,2100 ," HK$129.20 "',
        ',MS-B50,50ml bottles with 0.3mm needle (3 bottles & 3 needles),6977151546241X3,22, HK$11.05 , HK$13.00 ,200,2,44,22.10',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae.csv', $csv);

    $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'purchase_order_uuid' => $po->uuid,
        'file' => $file,
    ])->assertOk();

    $product->refresh();
    expect((string) $product->barcode)->toBe('6977151546258');
    expect((string) $product->description)->toBe('Wash-Free airbrush');

    $po->refresh();
    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->count())->toBe(2);
});

it('blocks reimport when PO has inventory lots', function (): void {
    $product = Product::query()->create([
        'sku' => 'REIMP-1',
        'barcode' => null,
        'description' => 'Test',
        'type' => 'HG',
        'vendor' => 'Dspiae',
        'price' => '1.00',
        'available_qty' => 0,
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
        'vendor_currency_code' => 'HKD',
        'vendor_product_total' => '1292.00',
        'product_total' => '300.00',
        'fx_rate_to_cad' => '0.232198',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '64.6000',
        'qty_ordered' => 1,
        'qty_received' => 1,
    ]);

    InventoryLot::query()->create([
        'product_id' => $product->id,
        'purchase_order_item_id' => $item->id,
        'source_type' => 'po',
        'unit_cost' => '15.0000',
        'shipping_per_unit' => null,
        'qty_received' => 1,
        'qty_remaining' => 1,
        'received_at' => now(),
    ]);

    $csv = implode("\n", [
        'DSPIAE,,,,,,,,,,',
        'Total Weight/g,,,,,2100,Price/HKD,,,,1292.00',
        'Product,SKU,Product name,Barcode,Weight/g,Wholesale price,Recommended Retail Price,Each carton contain,Required Quantity / pcs (Carton Multiple),Total weight/g,Total Amount for Single',
        ',REIMP-1,Test,6977151546258,105, HK$64.60 , HK$76.00 ,20,1,2100 ," HK$64.60 "',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae.csv', $csv);

    $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'purchase_order_uuid' => $po->uuid,
        'file' => $file,
    ])->assertStatus(422)
        ->assertJsonPath('issues.0.kind', 'reimport_not_allowed');
});

it('reimports into a PO when reset_receipt_before_reimport clears lots and qty received', function (): void {
    $product = Product::query()->create([
        'sku' => 'REIMP-RESET-1',
        'barcode' => null,
        'description' => 'Test',
        'type' => 'HG',
        'vendor' => 'Dspiae',
        'price' => '1.00',
        'available_qty' => 0,
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
        'vendor_currency_code' => 'HKD',
        'vendor_product_total' => '1292.00',
        'product_total' => '300.00',
        'fx_rate_to_cad' => '0.232198',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '64.6000',
        'qty_ordered' => 1,
        'qty_received' => 1,
    ]);

    $lot = InventoryLot::query()->create([
        'product_id' => $product->id,
        'purchase_order_item_id' => $item->id,
        'source_type' => 'po',
        'unit_cost' => '15.0000',
        'shipping_per_unit' => null,
        'qty_received' => 1,
        'qty_remaining' => 1,
        'received_at' => now(),
    ]);

    $csv = implode("\n", [
        'DSPIAE,,,,,,,,,,',
        'Total Weight/g,,,,,2100,Price/HKD,,,,1292.00',
        'Product,SKU,Product name,Barcode,Weight/g,Wholesale price,Recommended Retail Price,Each carton contain,Required Quantity / pcs (Carton Multiple),Total weight/g,Total Amount for Single',
        ',REIMP-RESET-1,Test,6977151546258,105, HK$64.60 , HK$76.00 ,20,1,2100 ," HK$64.60 "',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae.csv', $csv);

    $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'purchase_order_uuid' => $po->uuid,
        'file' => $file,
        'reset_receipt_before_reimport' => true,
    ])->assertOk();

    expect(InventoryLot::query()->whereKey($lot->id)->exists())->toBeFalse();

    $po->refresh();
    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->count())->toBe(1);
});
