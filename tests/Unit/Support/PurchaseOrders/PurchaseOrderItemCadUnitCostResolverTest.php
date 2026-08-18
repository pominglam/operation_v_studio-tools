<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\PurchaseOrders\PurchaseOrderItemCadUnitCostResolver;

it('returns CAD unit cost for domestic PO lines', function (): void {
    $po = new PurchaseOrder(['vendor_currency_code' => 'CAD']);
    $item = new PurchaseOrderItem(['unit_cost' => '12.34']);

    expect((new PurchaseOrderItemCadUnitCostResolver)->resolve($item, $po))->toBe('12.34');
});

it('converts vendor currency unit cost to CAD using PO FX rate', function (): void {
    $po = new PurchaseOrder([
        'vendor_currency_code' => 'HKD',
        'fx_rate_to_cad' => '0.180000',
    ]);
    $item = new PurchaseOrderItem([
        'vendor_unit_cost' => '100.0000',
        'unit_cost' => null,
    ]);

    expect((new PurchaseOrderItemCadUnitCostResolver)->resolve($item, $po))->toBe('18.00');
});
