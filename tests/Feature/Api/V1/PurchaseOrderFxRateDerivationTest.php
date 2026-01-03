<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\Product;
use App\Models\PurchaseOrderItem;

it('derives fx_rate_to_cad from product_total (CAD) and vendor_product_total (foreign) when currency != CAD', function (): void {
    $product = Product::query()->create([
        'sku' => 'PO-FX-1',
        'barcode' => null,
        'description' => 'FX test',
        'type' => 'HG',
        'vendor' => 'Dspiae',
        'price' => '1.00',
        'available_qty' => 0,
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
        'vendor_currency_code' => 'HKD',
        'vendor_product_total' => '1292.00',
        'product_total' => null,
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        // Simulate vendor currency cost stored in unit_cost pre-fix
        'unit_cost' => '64.6000',
        'qty_ordered' => 1,
    ]);

    $this->patchJson("/api/v1/purchase-orders/{$po->uuid}", [
        'product_total' => 300,
    ])->assertOk()
        ->assertJsonPath('data.vendor_currency_code', 'HKD')
        ->assertJsonPath('data.vendor_product_total', '1292.00')
        ->assertJsonPath('data.product_total', '300.00')
        ->assertJsonPath('data.fx_rate_to_cad', '0.232198');

    $po->refresh();
    expect((string) $po->fx_rate_to_cad)->toBe('0.232198');

    $item->refresh();
    expect((string) $item->vendor_unit_cost)->toBe('64.6000');
    expect((string) $item->unit_cost)->toBe('15.0000');
});

it('does not set fx_rate_to_cad when vendor currency is CAD', function (): void {
    $po = PurchaseOrder::query()->create([
        'vendor' => 'Local',
        'vendor_currency_code' => 'CAD',
        'vendor_product_total' => '100.00',
        'product_total' => '100.00',
    ]);

    $this->patchJson("/api/v1/purchase-orders/{$po->uuid}", [
        'product_total' => 100,
    ])->assertOk()
        ->assertJsonPath('data.fx_rate_to_cad', null);

    $po->refresh();
    expect($po->fx_rate_to_cad)->toBeNull();
});

