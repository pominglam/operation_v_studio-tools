<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Carbon\CarbonImmutable;

it('returns purchase order lines for a product with computed ship/surcharge/landed', function (): void {
    $p = Product::query()->create([
        'sku' => 'PO-LINES-1',
        'description' => 'Test product',
    ]);
    $other = Product::query()->create([
        'sku' => 'PO-LINES-2',
        'description' => 'Other product',
    ]);

    $po1 = PurchaseOrder::query()->create([
        'vendor' => 'Vendor A',
        'shipping_total' => '10.00',
        'surcharge_total' => '2.00',
        'ordered_date' => '2026-01-01',
        'created_at' => CarbonImmutable::parse('2026-01-02')->startOfDay(),
        'updated_at' => CarbonImmutable::parse('2026-01-02')->startOfDay(),
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po1->id,
        'product_id' => $p->id,
        'sku' => $p->sku,
        'vendor' => 'Vendor A',
        'unit_cost' => '4.00',
        'qty_ordered' => 2,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po1->id,
        'product_id' => $other->id,
        'sku' => $other->sku,
        'vendor' => 'Vendor A',
        'unit_cost' => '1.00',
        'qty_ordered' => 3,
    ]);

    $po2 = PurchaseOrder::query()->create([
        'vendor' => 'Vendor B',
        'shipping_total' => '0.00',
        'surcharge_total' => '0.00',
        'ordered_date' => '2026-01-03',
        'created_at' => CarbonImmutable::parse('2026-01-04')->startOfDay(),
        'updated_at' => CarbonImmutable::parse('2026-01-04')->startOfDay(),
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po2->id,
        'product_id' => $p->id,
        'sku' => $p->sku,
        'vendor' => 'Vendor B',
        'unit_cost' => '5.00',
        'qty_ordered' => 1,
    ]);

    $res = $this->getJson("/api/v1/products/{$p->uuid}/po-lines?limit=10");
    $res->assertOk()
        ->assertJsonCount(2, 'lines')
        // po2 is newer by created_at
        ->assertJsonPath('lines.0.vendor', 'Vendor B')
        ->assertJsonPath('lines.0.unit_cost', '5.00')
        ->assertJsonPath('lines.0.ship_per_unit', '0.00')
        ->assertJsonPath('lines.0.surcharge_per_unit', '0.00')
        ->assertJsonPath('lines.0.landed_unit_cost', '5.00')
        // po1 allocation units = 2 + 3 = 5 => ship/unit 2.00, surcharge/unit 0.40
        ->assertJsonPath('lines.1.vendor', 'Vendor A')
        ->assertJsonPath('lines.1.unit_cost', '4.00')
        ->assertJsonPath('lines.1.ship_per_unit', '2.00')
        ->assertJsonPath('lines.1.surcharge_per_unit', '0.40')
        ->assertJsonPath('lines.1.landed_unit_cost', '6.40');
});

it('returns empty po-lines list when a product has no purchase order items', function (): void {
    $p = Product::query()->create([
        'sku' => 'PO-LINES-EMPTY',
        'description' => 'No lines',
    ]);

    $this->getJson("/api/v1/products/{$p->uuid}/po-lines")
        ->assertOk()
        ->assertJson([
            'lines' => [],
        ]);
});

