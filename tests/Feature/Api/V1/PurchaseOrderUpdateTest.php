<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('updates purchase order header and recomputes related po lots shipping_per_unit and received_at', function (): void {
    $product = Product::query()->create([
        'sku' => 'PO-UPD-1',
        'barcode' => null,
        'description' => 'PO Update Product',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'price' => '10.00',
        'available_qty' => 0,
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'received_date' => '2025-12-01',
        'shipping_total' => '0.00',
        'notes' => null,
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.0000',
        'qty_received' => 10,
    ]);

    $lot = InventoryLot::query()->create([
        'product_id' => $product->id,
        'purchase_order_item_id' => $item->id,
        'source_type' => 'po',
        'unit_cost' => '10.0000',
        'shipping_per_unit' => null,
        'qty_received' => 10,
        'qty_remaining' => 10,
        'received_at' => now()->subDays(5),
    ]);

    $this->patchJson("/api/v1/purchase-orders/{$po->uuid}", [
        'shipping_total' => 100,
        'surcharge_total' => 12.34,
        'product_total' => 250.5,
        'supplier_order_id' => 'SUP-12345',
        'shipment_tracking_numbers' => ['1Z999AA10123456784', 'RR123456789CN'],
        'estimated_arrival_date' => '2025-12-08',
        'received_date' => '2025-12-10',
        'is_done' => true,
        'notes' => 'Updated',
    ])->assertOk()
        ->assertJsonPath('data.id', $po->uuid)
        ->assertJsonPath('data.shipping_total', '100.00')
        ->assertJsonPath('data.surcharge_total', '12.34')
        ->assertJsonPath('data.product_total', '250.50')
        ->assertJsonPath('data.supplier_order_id', 'SUP-12345')
        ->assertJsonPath('data.shipment_tracking_numbers.0', '1Z999AA10123456784')
        ->assertJsonPath('data.shipment_tracking_numbers.1', 'RR123456789CN')
        ->assertJsonPath('data.estimated_arrival_date', '2025-12-08')
        ->assertJsonPath('data.received_date', '2025-12-10')
        ->assertJsonPath('data.is_done', true)
        ->assertJsonPath('data.notes', 'Updated');

    $lot->refresh();
    expect((string) $lot->shipping_per_unit)->toBe('10.000000');
    expect($lot->received_at?->toDateString())->toBe('2025-12-10');
});

it('updates exclude_from_latest_arrivals_ordering on purchase order header', function (): void {
    $po = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'received_date' => '2025-12-01',
        'exclude_from_latest_arrivals_ordering' => false,
    ]);

    $this->patchJson("/api/v1/purchase-orders/{$po->uuid}", [
        'exclude_from_latest_arrivals_ordering' => true,
    ])->assertOk()
        ->assertJsonPath('data.exclude_from_latest_arrivals_ordering', true);

    $po->refresh();
    expect($po->exclude_from_latest_arrivals_ordering)->toBeTrue();
    expect($po->received_date?->toDateString())->toBe('2025-12-01');
});

it('rejects invalid purchase order shipment tracking number lists', function (): void {
    $po = PurchaseOrder::query()->create(['vendor' => 'Plamod']);

    $this->patchJson("/api/v1/purchase-orders/{$po->uuid}", [
        'shipment_tracking_numbers' => [str_repeat('A', 256)],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('shipment_tracking_numbers.0');

    $this->patchJson("/api/v1/purchase-orders/{$po->uuid}", [
        'shipment_tracking_numbers' => array_fill(0, 41, 'TRACKING'),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('shipment_tracking_numbers');
});
