<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

function createCombinedPaymentPo(
    string $sku,
    string $vendorProductTotal,
    string $vendorShippingTotal,
    string $shipmentMethod,
    int $qtyReceived = 0,
): PurchaseOrder {
    $product = Product::query()->create([
        'sku' => $sku,
        'barcode' => null,
        'description' => "Combined payment {$sku}",
        'type' => 'HG',
        'vendor' => 'Dspiae',
        'price' => '1.00',
        'available_qty' => 0,
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
        'vendor_currency_code' => 'HKD',
        'vendor_product_total' => $vendorProductTotal,
        'vendor_shipping_total' => $vendorShippingTotal,
        'shipping_total' => '999.00',
        'surcharge_total' => '4.50',
        'shipment_method' => $shipmentMethod,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => null,
        'vendor_unit_cost' => $vendorProductTotal,
        'qty_ordered' => 1,
        'qty_received' => $qtyReceived > 0 ? $qtyReceived : null,
    ]);

    return $po;
}

it('previews and records one CAD payment across separate HKD product and shipping totals', function (): void {
    $air = createCombinedPaymentPo('PAY-AIR', '1000.00', '100.00', 'air');
    $sea = createCombinedPaymentPo('PAY-SEA', '500.00', '50.00', 'sea');

    $payload = [
        'purchase_order_ids' => [$air->uuid, $sea->uuid],
        'total_paid_cad' => '300.00',
        'includes_shipping' => true,
    ];

    $preview = $this->postJson('/api/v1/purchase-orders/combined-payments/preview', $payload)
        ->assertOk()
        ->assertJsonPath('data.vendor_currency_code', 'HKD')
        ->assertJsonPath('data.vendor_total', '1650.00')
        ->assertJsonPath('data.total_paid_cad', '300.00')
        ->assertJsonPath('data.fx_rate_to_cad', '0.181818')
        ->assertJsonPath('data.allocations.0.purchase_order_id', $air->uuid)
        ->assertJsonPath('data.allocations.0.product_total_cad', '181.82')
        ->assertJsonPath('data.allocations.0.shipping_total_cad', '18.18')
        ->assertJsonPath('data.allocations.1.purchase_order_id', $sea->uuid)
        ->assertJsonPath('data.allocations.1.product_total_cad', '90.91')
        ->assertJsonPath('data.allocations.1.shipping_total_cad', '9.09');

    expect($preview->json('data.allocations.0.shipment_method'))->toBe('air')
        ->and($preview->json('data.allocations.1.shipment_method'))->toBe('sea');

    $response = $this->postJson('/api/v1/purchase-orders/combined-payments', $payload)
        ->assertCreated()
        ->assertJsonPath('data.total_paid_cad', '300.00')
        ->assertJsonPath('data.allocations.0.product_total_cad', '181.82')
        ->assertJsonPath('data.allocations.1.shipping_total_cad', '9.09');

    $paymentId = $response->json('data.id');
    $this->assertDatabaseHas('purchase_order_combined_payments', [
        'uuid' => $paymentId,
        'vendor_currency_code' => 'HKD',
        'vendor_total' => '1650.00',
        'total_paid_cad' => '300.00',
        'includes_shipping' => true,
    ]);
    $this->assertDatabaseCount('purchase_order_combined_payment_lines', 2);

    $air->refresh();
    $sea->refresh();
    expect((string) $air->vendor_product_total)->toBe('1000.00')
        ->and((string) $air->vendor_shipping_total)->toBe('100.00')
        ->and((string) $air->product_total)->toBe('181.82')
        ->and((string) $air->shipping_total)->toBe('18.18')
        ->and((string) $air->surcharge_total)->toBe('4.50')
        ->and((string) $air->fx_rate_to_cad)->toBe('0.181818')
        ->and((string) $sea->vendor_product_total)->toBe('500.00')
        ->and((string) $sea->vendor_shipping_total)->toBe('50.00')
        ->and((string) $sea->product_total)->toBe('90.91')
        ->and((string) $sea->shipping_total)->toBe('9.09')
        ->and((string) $sea->fx_rate_to_cad)->toBe('0.181818');

    $airItem = PurchaseOrderItem::query()->where('purchase_order_id', $air->id)->firstOrFail();
    $seaItem = PurchaseOrderItem::query()->where('purchase_order_id', $sea->id)->firstOrFail();
    expect((string) $airItem->vendor_unit_cost)->toBe('1000.0000')
        ->and((string) $airItem->unit_cost)->toBe('181.82')
        ->and((string) $seaItem->vendor_unit_cost)->toBe('500.0000')
        ->and((string) $seaItem->unit_cost)->toBe('90.91');

    $this->deleteJson("/api/v1/purchase-orders/{$air->uuid}")
        ->assertConflict()
        ->assertJsonPath(
            'message',
            'Cannot delete a purchase order linked to a combined payment.',
        );
});

it('leaves each shipment shipping total unchanged for a products-only payment', function (): void {
    $air = createCombinedPaymentPo('PAY-PRODUCT-AIR', '750.00', '80.00', 'air');
    $sea = createCombinedPaymentPo('PAY-PRODUCT-SEA', '250.00', '30.00', 'sea');

    $this->postJson('/api/v1/purchase-orders/combined-payments', [
        'purchase_order_ids' => [$air->uuid, $sea->uuid],
        'total_paid_cad' => '200.00',
        'includes_shipping' => false,
    ])->assertCreated()
        ->assertJsonPath('data.vendor_total', '1000.00')
        ->assertJsonPath('data.allocations.0.product_total_cad', '150.00')
        ->assertJsonPath('data.allocations.0.shipping_total_cad', '999.00')
        ->assertJsonPath('data.allocations.1.product_total_cad', '50.00')
        ->assertJsonPath('data.allocations.1.shipping_total_cad', '999.00');

    $air->refresh();
    $sea->refresh();
    expect((string) $air->shipping_total)->toBe('999.00')
        ->and((string) $sea->shipping_total)->toBe('999.00');
});

it('records exact per-PO CAD product and shipping amounts entered by the operator', function (): void {
    $air = createCombinedPaymentPo('PAY-MANUAL-AIR', '1000.00', '100.00', 'air');
    $sea = createCombinedPaymentPo('PAY-MANUAL-SEA', '500.00', '50.00', 'sea');

    $payload = [
        'purchase_order_ids' => [$air->uuid, $sea->uuid],
        'total_paid_cad' => '300.00',
        'includes_shipping' => true,
        'allocations' => [
            [
                'purchase_order_id' => $air->uuid,
                'product_total_cad' => '150.00',
                'shipping_total_cad' => '30.00',
            ],
            [
                'purchase_order_id' => $sea->uuid,
                'product_total_cad' => '100.00',
                'shipping_total_cad' => '20.00',
            ],
        ],
    ];

    $this->postJson('/api/v1/purchase-orders/combined-payments/preview', $payload)
        ->assertOk()
        ->assertJsonPath('data.allocations.0.product_total_cad', '150.00')
        ->assertJsonPath('data.allocations.0.shipping_total_cad', '30.00')
        ->assertJsonPath('data.allocations.0.fx_rate_to_cad', '0.150000')
        ->assertJsonPath('data.allocations.1.product_total_cad', '100.00')
        ->assertJsonPath('data.allocations.1.shipping_total_cad', '20.00')
        ->assertJsonPath('data.allocations.1.fx_rate_to_cad', '0.200000');

    $this->postJson('/api/v1/purchase-orders/combined-payments', $payload)
        ->assertCreated();

    $air->refresh();
    $sea->refresh();
    expect((string) $air->product_total)->toBe('150.00')
        ->and((string) $air->shipping_total)->toBe('30.00')
        ->and((string) $air->fx_rate_to_cad)->toBe('0.150000')
        ->and((string) $sea->product_total)->toBe('100.00')
        ->and((string) $sea->shipping_total)->toBe('20.00')
        ->and((string) $sea->fx_rate_to_cad)->toBe('0.200000');

    $airItem = PurchaseOrderItem::query()->where('purchase_order_id', $air->id)->firstOrFail();
    $seaItem = PurchaseOrderItem::query()->where('purchase_order_id', $sea->id)->firstOrFail();
    expect((string) $airItem->unit_cost)->toBe('150.00')
        ->and((string) $seaItem->unit_cost)->toBe('100.00');
});

it('allocates separately entered combined CAD product and shipping totals', function (): void {
    $air = createCombinedPaymentPo('PAY-SPLIT-AIR', '1000.00', '100.00', 'air');
    $sea = createCombinedPaymentPo('PAY-SPLIT-SEA', '500.00', '50.00', 'sea');

    $payload = [
        'purchase_order_ids' => [$air->uuid, $sea->uuid],
        'total_paid_cad' => '300.00',
        'product_paid_cad' => '240.00',
        'shipping_paid_cad' => '60.00',
        'includes_shipping' => true,
    ];

    $this->postJson('/api/v1/purchase-orders/combined-payments/preview', $payload)
        ->assertOk()
        ->assertJsonPath('data.total_paid_cad', '300.00')
        ->assertJsonPath('data.allocations.0.product_total_cad', '160.00')
        ->assertJsonPath('data.allocations.0.shipping_total_cad', '40.00')
        ->assertJsonPath('data.allocations.0.fx_rate_to_cad', '0.160000')
        ->assertJsonPath('data.allocations.1.product_total_cad', '80.00')
        ->assertJsonPath('data.allocations.1.shipping_total_cad', '20.00')
        ->assertJsonPath('data.allocations.1.fx_rate_to_cad', '0.160000');

    $this->postJson('/api/v1/purchase-orders/combined-payments/preview', [
        ...$payload,
        'allocations' => [
            [
                'purchase_order_id' => $air->uuid,
                'product_total_cad' => '150.00',
                'shipping_total_cad' => '30.00',
            ],
            [
                'purchase_order_id' => $sea->uuid,
                'product_total_cad' => '100.00',
                'shipping_total_cad' => '20.00',
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonPath(
            'message',
            'Manual CAD allocations must match the combined product and shipping amounts.',
        );

    $this->postJson('/api/v1/purchase-orders/combined-payments', $payload)->assertCreated();
    $air->refresh();
    $sea->refresh();
    expect((string) $air->product_total)->toBe('160.00')
        ->and((string) $air->shipping_total)->toBe('40.00')
        ->and((string) $air->fx_rate_to_cad)->toBe('0.160000')
        ->and((string) $sea->product_total)->toBe('80.00')
        ->and((string) $sea->shipping_total)->toBe('20.00')
        ->and((string) $sea->fx_rate_to_cad)->toBe('0.160000');
});

it('rejects a combined product and shipping split that does not equal total paid', function (): void {
    $air = createCombinedPaymentPo('PAY-SPLIT-BAD-AIR', '1000.00', '100.00', 'air');
    $sea = createCombinedPaymentPo('PAY-SPLIT-BAD-SEA', '500.00', '50.00', 'sea');

    $this->postJson('/api/v1/purchase-orders/combined-payments/preview', [
        'purchase_order_ids' => [$air->uuid, $sea->uuid],
        'total_paid_cad' => '300.00',
        'product_paid_cad' => '240.00',
        'shipping_paid_cad' => '59.99',
        'includes_shipping' => true,
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Combined CAD product and shipping amounts must equal total paid.');
});

it('rejects manual CAD allocations that do not reconcile to total paid', function (): void {
    $air = createCombinedPaymentPo('PAY-MANUAL-BAD-AIR', '1000.00', '100.00', 'air');
    $sea = createCombinedPaymentPo('PAY-MANUAL-BAD-SEA', '500.00', '50.00', 'sea');

    $this->postJson('/api/v1/purchase-orders/combined-payments/preview', [
        'purchase_order_ids' => [$air->uuid, $sea->uuid],
        'total_paid_cad' => '300.00',
        'includes_shipping' => true,
        'allocations' => [
            [
                'purchase_order_id' => $air->uuid,
                'product_total_cad' => '150.00',
                'shipping_total_cad' => '30.00',
            ],
            [
                'purchase_order_id' => $sea->uuid,
                'product_total_cad' => '100.00',
                'shipping_total_cad' => '19.99',
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Manual CAD allocations must add up exactly to total paid.');
});

it('rejects combined payment when a selected PO has received inventory', function (): void {
    $air = createCombinedPaymentPo('PAY-RECEIVED', '100.00', '10.00', 'air', 1);
    $sea = createCombinedPaymentPo('PAY-DRAFT', '100.00', '10.00', 'sea');

    $this->postJson('/api/v1/purchase-orders/combined-payments/preview', [
        'purchase_order_ids' => [$air->uuid, $sea->uuid],
        'total_paid_cad' => '40.00',
        'includes_shipping' => true,
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Combined payment cannot change a PO after inventory has been received.');
});
