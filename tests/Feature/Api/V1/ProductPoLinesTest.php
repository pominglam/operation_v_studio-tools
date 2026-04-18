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
        'qty_shipped' => 1,
        'qty_received' => 1,
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
        'qty_shipped' => 2,
        'qty_received' => 1,
    ]);

    $res = $this->getJson("/api/v1/products/{$p->uuid}/po-lines?limit=10");
    $res->assertOk()
        ->assertJsonCount(2, 'lines')
        // po2 is newer by created_at
        ->assertJsonPath('lines.0.vendor', 'Vendor B')
        ->assertJsonPath('lines.0.qty_shipped', 2)
        ->assertJsonPath('lines.0.qty_received', 1)
        ->assertJsonPath('lines.0.unit_cost', '5.00')
        ->assertJsonPath('lines.0.ship_per_unit', '0.00')
        ->assertJsonPath('lines.0.surcharge_per_unit', '0.00')
        ->assertJsonPath('lines.0.landed_unit_cost', '5.00')
        // po1 allocation units = 2 + 3 = 5 => ship/unit 2.00, surcharge/unit 0.40
        ->assertJsonPath('lines.1.vendor', 'Vendor A')
        ->assertJsonPath('lines.1.qty_shipped', 1)
        ->assertJsonPath('lines.1.qty_received', 1)
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

it('falls back to ordered allocation when received_date exists but qty_received totals are zero', function (): void {
    $p = Product::query()->create([
        'sku' => 'PO-LINES-FALLBACK',
        'description' => 'Fallback allocation product',
    ]);

    $other = Product::query()->create([
        'sku' => 'PO-LINES-FALLBACK-OTHER',
        'description' => 'Fallback allocation other',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Vendor Fallback',
        'shipping_total' => '30.00',
        'surcharge_total' => '0.00',
        'ordered_date' => '2026-03-01',
        'received_date' => '2026-03-05',
        'created_at' => CarbonImmutable::parse('2026-03-06')->startOfDay(),
        'updated_at' => CarbonImmutable::parse('2026-03-06')->startOfDay(),
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => $p->sku,
        'vendor' => 'Vendor Fallback',
        'unit_cost' => '7.19',
        'qty_ordered' => 2,
        'qty_shipped' => null,
        'qty_received' => null,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $other->id,
        'sku' => $other->sku,
        'vendor' => 'Vendor Fallback',
        'unit_cost' => '1.00',
        'qty_ordered' => 3,
        'qty_shipped' => null,
        'qty_received' => null,
    ]);

    // No qty_received values => sum_received=0, sum_ordered=5.
    // Expected fallback ship/unit = 30 / 5 = 6.00 (not 0.00).
    $this->getJson("/api/v1/products/{$p->uuid}/po-lines?limit=10")
        ->assertOk()
        ->assertJsonPath('lines.0.ship_per_unit', '6.00');
});

it('uses received allocation when qty_received exists even if received_date is null', function (): void {
    $p = Product::query()->create([
        'sku' => 'PO-LINES-RECV-WITHOUT-DATE',
        'description' => 'Received qty without received date',
    ]);

    $other = Product::query()->create([
        'sku' => 'PO-LINES-RECV-WITHOUT-DATE-OTHER',
        'description' => 'Received qty without received date other',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Vendor Received No Date',
        'shipping_total' => '30.00',
        'surcharge_total' => '0.00',
        'ordered_date' => '2026-03-01',
        'received_date' => null,
        'created_at' => CarbonImmutable::parse('2026-03-06')->startOfDay(),
        'updated_at' => CarbonImmutable::parse('2026-03-06')->startOfDay(),
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => $p->sku,
        'vendor' => 'Vendor Received No Date',
        'unit_cost' => '7.19',
        'qty_ordered' => 5,
        'qty_received' => 2,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $other->id,
        'sku' => $other->sku,
        'vendor' => 'Vendor Received No Date',
        'unit_cost' => '1.00',
        'qty_ordered' => 5,
        'qty_received' => 1,
    ]);

    // sum_received=3, sum_ordered=10. New rule: use received whenever > 0.
    // shipping/unit = 30 / 3 = 10.00 (not 3.00).
    $this->getJson("/api/v1/products/{$p->uuid}/po-lines?limit=10")
        ->assertOk()
        ->assertJsonPath('lines.0.ship_per_unit', '10.00');
});

it('uses entered qty_received totals even when they are zero', function (): void {
    $p = Product::query()->create([
        'sku' => 'PO-LINES-RECV-ZERO',
        'description' => 'Received zero allocation product',
    ]);

    $other = Product::query()->create([
        'sku' => 'PO-LINES-RECV-ZERO-OTHER',
        'description' => 'Received zero allocation other',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Vendor Received Zero',
        'shipping_total' => '30.00',
        'surcharge_total' => '0.00',
        'ordered_date' => '2026-03-01',
        'received_date' => null,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => $p->sku,
        'vendor' => 'Vendor Received Zero',
        'unit_cost' => '7.19',
        'qty_ordered' => 5,
        'qty_received' => 0,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $other->id,
        'sku' => $other->sku,
        'vendor' => 'Vendor Received Zero',
        'unit_cost' => '1.00',
        'qty_ordered' => 5,
        'qty_received' => 0,
    ]);

    // Received qtys are entered (both 0), so allocation uses received total (0), not ordered (10).
    // With zero allocation units, ship/unit resolves to 0.00.
    $this->getJson("/api/v1/products/{$p->uuid}/po-lines?limit=10")
        ->assertOk()
        ->assertJsonPath('lines.0.ship_per_unit', '0.00');
});

it('sorts po lines with not-arrived first by estimated arrival desc, then arrived by received desc', function (): void {
    $p = Product::query()->create([
        'sku' => 'PO-LINES-SORT',
        'description' => 'Sort behavior product',
    ]);

    $notArrivedLater = PurchaseOrder::query()->create([
        'vendor' => 'Vendor NA 2',
        'estimated_arrival_date' => '2026-04-10',
        'received_date' => null,
        'created_at' => CarbonImmutable::parse('2026-03-10')->startOfDay(),
        'updated_at' => CarbonImmutable::parse('2026-03-10')->startOfDay(),
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $notArrivedLater->id,
        'product_id' => $p->id,
        'sku' => $p->sku,
        'vendor' => 'Vendor NA 2',
        'unit_cost' => '1.00',
        'qty_ordered' => 1,
    ]);

    $notArrivedEarlier = PurchaseOrder::query()->create([
        'vendor' => 'Vendor NA 1',
        'estimated_arrival_date' => '2026-04-01',
        'received_date' => null,
        'created_at' => CarbonImmutable::parse('2026-03-11')->startOfDay(),
        'updated_at' => CarbonImmutable::parse('2026-03-11')->startOfDay(),
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $notArrivedEarlier->id,
        'product_id' => $p->id,
        'sku' => $p->sku,
        'vendor' => 'Vendor NA 1',
        'unit_cost' => '1.00',
        'qty_ordered' => 1,
    ]);

    $arrivedLater = PurchaseOrder::query()->create([
        'vendor' => 'Vendor A 2',
        'estimated_arrival_date' => '2026-03-15',
        'received_date' => '2026-03-20',
        'created_at' => CarbonImmutable::parse('2026-03-12')->startOfDay(),
        'updated_at' => CarbonImmutable::parse('2026-03-12')->startOfDay(),
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $arrivedLater->id,
        'product_id' => $p->id,
        'sku' => $p->sku,
        'vendor' => 'Vendor A 2',
        'unit_cost' => '1.00',
        'qty_ordered' => 1,
        'qty_received' => 1,
    ]);

    $arrivedEarlier = PurchaseOrder::query()->create([
        'vendor' => 'Vendor A 1',
        'estimated_arrival_date' => '2026-03-01',
        'received_date' => '2026-03-10',
        'created_at' => CarbonImmutable::parse('2026-03-13')->startOfDay(),
        'updated_at' => CarbonImmutable::parse('2026-03-13')->startOfDay(),
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $arrivedEarlier->id,
        'product_id' => $p->id,
        'sku' => $p->sku,
        'vendor' => 'Vendor A 1',
        'unit_cost' => '1.00',
        'qty_ordered' => 1,
        'qty_received' => 1,
    ]);

    $res = $this->getJson("/api/v1/products/{$p->uuid}/po-lines?limit=10");
    $res->assertOk()->assertJsonCount(4, 'lines');

    expect($res->json('lines.0.vendor'))->toBe('Vendor NA 2');
    expect($res->json('lines.1.vendor'))->toBe('Vendor NA 1');
    expect($res->json('lines.2.vendor'))->toBe('Vendor A 2');
    expect($res->json('lines.3.vendor'))->toBe('Vendor A 1');
});
