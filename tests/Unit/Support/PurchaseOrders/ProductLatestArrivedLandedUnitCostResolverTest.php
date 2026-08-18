<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\PurchaseOrders\ProductLatestArrivedLandedUnitCostResolver;
use App\Support\PurchaseOrders\PurchaseOrderItemCadUnitCostResolver;
use App\Support\PurchaseOrders\PurchaseOrderLandedUnitCostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('uses the latest PO with entered shipping and allocates shipping across the whole PO', function (): void {
    $product = Product::query()->create([
        'sku' => 'ARRIVED-LANDED-SKU',
        'description' => 'Arrived landed product',
    ]);

    $companionProduct = Product::query()->create([
        'sku' => 'ARRIVED-LANDED-COMPANION',
        'description' => 'Companion allocation product',
    ]);

    $onShelvesPo = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'shipping_total' => '10.00',
        'ordered_date' => '2026-01-01',
        'received_date' => '2026-01-01',
        'fully_on_shelves_date' => '2026-01-05',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $onShelvesPo->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '40.00',
        'qty_ordered' => 2,
        'qty_received' => 2,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $onShelvesPo->id,
        'product_id' => $companionProduct->id,
        'sku' => $companionProduct->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 8,
        'qty_received' => 8,
    ]);

    $latestCostedPo = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'shipping_total' => '20.00',
        'ordered_date' => '2026-03-01',
        'received_date' => '2026-02-01',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $latestCostedPo->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '50.00',
        'qty_ordered' => 2,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $latestCostedPo->id,
        'product_id' => $companionProduct->id,
        'sku' => $companionProduct->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 8,
    ]);

    $newestPoWithoutShipping = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'shipping_total' => null,
        'ordered_date' => '2026-04-01',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $newestPoWithoutShipping->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '60.00',
        'qty_ordered' => 1,
    ]);

    $resolver = new ProductLatestArrivedLandedUnitCostResolver(
        new PurchaseOrderLandedUnitCostResolver(new PurchaseOrderItemCadUnitCostResolver),
    );

    expect($resolver->landedByProductId([(int) $product->id]))->toBe([
        (int) $product->id => '52.00',
    ]);
});
