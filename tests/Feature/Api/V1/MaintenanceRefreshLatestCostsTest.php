<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('recomputes cached latest unit/landed costs via maintenance endpoint', function (): void {
    $p = Product::query()->create([
        'sku' => 'COST-REFRESH-1',
        'description' => 'Cost refresh product',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Vendor A',
        'shipping_total' => '10.00',
        'surcharge_total' => '0.00',
        'ordered_date' => '2026-01-01',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => $p->sku,
        'vendor' => 'Vendor A',
        'unit_cost' => '4.00',
        'qty_ordered' => 2,
    ]);

    // Allocation units = 2 => ship/unit = 5.00 => landed = 9.00
    $this->postJson('/api/v1/maintenance/refresh-latest-costs')
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    $p->refresh();
    expect((string) $p->latest_unit_cost)->toBe('4.00')
        ->and((string) $p->latest_landed_unit_cost)->toBe('9.00');
});
