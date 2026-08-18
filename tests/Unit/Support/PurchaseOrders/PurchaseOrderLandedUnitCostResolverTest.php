<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\PurchaseOrders\PurchaseOrderItemCadUnitCostResolver;
use App\Support\PurchaseOrders\PurchaseOrderLandedUnitCostResolver;

it('computes PO line landed cost from unit cost plus allocated shipping and surcharge', function (): void {
    $po = new PurchaseOrder([
        'shipping_total' => '10.00',
        'surcharge_total' => '4.00',
    ]);

    $item = new PurchaseOrderItem([
        'product_id' => 101,
        'unit_cost' => '50.00',
        'qty_ordered' => 2,
    ]);

    $landed = (new PurchaseOrderLandedUnitCostResolver(new PurchaseOrderItemCadUnitCostResolver))->landedByProductId($po, [$item]);

    expect($landed[101] ?? null)->toBe('57.00');
});
