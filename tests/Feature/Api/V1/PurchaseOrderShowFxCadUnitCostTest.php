<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('returns item unit_cost in CAD for a foreign-currency PO when fx_rate_to_cad is set (legacy fallback)', function (): void {
    $product = Product::query()->create([
        'sku' => 'PO-SHOW-FX-1',
        'barcode' => null,
        'description' => 'Show FX test',
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

    // Legacy scenario: unit_cost held vendor currency, vendor_unit_cost was not stored yet.
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '64.6000',
        'vendor_unit_cost' => null,
        'qty_ordered' => 1,
    ]);

    $this->getJson("/api/v1/purchase-orders/{$po->uuid}")
        ->assertOk()
        ->assertJsonPath('data.items.0.unit_cost', '15.00');
});
