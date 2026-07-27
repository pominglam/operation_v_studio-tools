<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Products\ProductSkuCascadeRenameService;
use App\Services\Products\WaterDecalSkuPrefixService;
use App\Services\PurchaseOrders\PurchaseOrderFxRecalculateService;
use App\Services\PurchaseOrders\PurchaseOrderWaterDecalLineAddService;

it('prefixes water decal skus and cascades rename to purchase order items', function (): void {
    $product = Product::query()->create([
        'sku' => 'MG-93',
        'description' => 'Water decal - MG Turn X',
        'main_type' => 'water decals',
        'type' => 'MG',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'HKD',
        'product_total' => '100.00',
        'vendor_product_total' => '500.0000',
        'fx_rate_to_cad' => '0.200000',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'MG-93',
        'vendor' => 'Dspiae',
        'vendor_unit_cost' => '500.0000',
        'qty_ordered' => 1,
    ]);

    $renamed = app(WaterDecalSkuPrefixService::class)->prefixAll('WD-');

    expect($renamed)->toBe([['from' => 'MG-93', 'to' => 'WD-MG-93']]);
    expect(Product::query()->where('sku', 'WD-MG-93')->exists())->toBeTrue();
    expect(PurchaseOrderItem::query()->where('sku', 'WD-MG-93')->exists())->toBeTrue();
});

it('adds water decal lines without changing fixed product total cad', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000101',
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'HKD',
        'product_total' => '100.00',
        'vendor_product_total' => '400.00',
        'fx_rate_to_cad' => '0.250000',
    ]);

    $existingProduct = Product::query()->create([
        'sku' => 'WD-EXISTING',
        'description' => 'Water decal - Existing',
        'main_type' => 'water decals',
        'type' => 'MG',
        'vendor' => 'Dspiae',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $existingProduct->id,
        'sku' => 'WD-EXISTING',
        'vendor' => 'Dspiae',
        'vendor_unit_cost' => '400.0000',
        'unit_cost' => '100.0000',
        'qty_ordered' => 1,
    ]);

    $created = app(PurchaseOrderWaterDecalLineAddService::class)->addLines($po->uuid, [[
        'sku' => 'WD-MG-224',
        'description' => 'Water decal - MG Turn A',
        'type' => 'MG',
        'vendor_unit_cost_hkd' => '100.0000',
        'qty_ordered' => 1,
    ]]);

    expect($created)->toHaveCount(1);

    $po->refresh();
    expect((string) $po->product_total)->toBe('100.00')
        ->and((string) $po->vendor_product_total)->toBe('500.00')
        ->and((string) $po->fx_rate_to_cad)->toBe('0.200000');

    $item = PurchaseOrderItem::query()->find($created[0]['item_id']);
    expect((string) $item->vendor_unit_cost)->toBe('100.0000')
        ->and((string) $item->unit_cost)->toBe('20.00');

    $product = Product::query()->where('sku', 'WD-MG-224')->first();
    expect($product)->not->toBeNull()
        ->and((string) $product->latest_unit_cost)->toBe('20.00')
        ->and((string) $product->latest_landed_unit_cost)->toBe('20.00');
});

it('recalculates fx from fixed product total and vendor line rollup', function (): void {
    $po = PurchaseOrder::query()->create([
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'HKD',
        'product_total' => '354.31',
    ]);

    $product = Product::query()->create([
        'sku' => 'WD-TEST',
        'description' => 'Water decal - Test',
        'main_type' => 'water decals',
        'type' => 'MG',
        'vendor' => 'Dspiae',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'WD-TEST',
        'vendor' => 'Dspiae',
        'vendor_unit_cost' => '100.0000',
        'qty_ordered' => 2,
    ]);

    $updated = app(PurchaseOrderFxRecalculateService::class)->recalculateFromFixedProductTotal($po);

    expect((string) $updated->product_total)->toBe('354.31')
        ->and((string) $updated->vendor_product_total)->toBe('200.00')
        ->and((string) $updated->fx_rate_to_cad)->toBe('1.771550');
});

it('rejects sku rename when target already exists', function (): void {
    Product::query()->create([
        'sku' => 'WD-MG-1',
        'description' => 'Existing',
        'main_type' => 'water decals',
        'type' => 'MG',
        'vendor' => 'Dspiae',
    ]);
    Product::query()->create([
        'sku' => 'MG-1',
        'description' => 'Other',
        'main_type' => 'water decals',
        'type' => 'MG',
        'vendor' => 'Dspiae',
    ]);

    app(ProductSkuCascadeRenameService::class)->rename('MG-1', 'WD-MG-1');
})->throws(InvalidArgumentException::class);
