<?php

declare(strict_types=1);

use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('overwrites qty_received from inventory check lines matched by trimmed sku', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130001',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => null,
        'sku' => '  IC-SKU-A  ',
        'vendor' => 'Plamod',
        'qty_ordered' => 10,
        'qty_shipped' => 8,
        'qty_received' => 1,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => null,
        'sku' => 'IC-SKU-B',
        'vendor' => 'Plamod',
        'qty_ordered' => 4,
        'qty_received' => 4,
    ]);

    $check = InventoryCheck::query()->create([
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeee130001',
        'source' => 'employee_scan',
        'workflow_state' => 'ready_for_review',
        'created_by_role' => 'employee',
    ]);

    InventoryCheckItem::query()->create([
        'inventory_check_id' => $check->id,
        'product_id' => null,
        'sku' => 'IC-SKU-A',
        'match_status' => 'matched',
        'quantity_in_store' => 7,
    ]);
    InventoryCheckItem::query()->create([
        'inventory_check_id' => $check->id,
        'product_id' => null,
        'sku' => 'IC-SKU-B',
        'match_status' => 'matched',
        'quantity_in_store' => 2,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/apply-inventory-check", [
        'inventory_check_id' => $check->uuid,
    ]);

    $res->assertOk();
    $res->assertJsonPath('ok', true);
    $res->assertJsonPath('data.lines_updated', 2);
    $res->assertJsonPath('data.reset.movements_deleted', 0);
    $res->assertJsonPath('data.reset.lots_deleted', 0);
    $res->assertJsonPath('data.reset.qty_received_cleared', 2);

    $items = PurchaseOrderItem::query()->where('purchase_order_id', '=', $po->id)->orderBy('id')->get();
    expect((int) $items[0]->qty_received)->toBe(7);
    expect((int) $items[1]->qty_received)->toBe(2);
});

it('aggregates multiple inventory check rows with the same sku', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130002',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => null,
        'sku' => 'DUP-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 20,
        'qty_received' => 0,
    ]);

    $check = InventoryCheck::query()->create([
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeee130002',
        'source' => 'csv_import',
        'workflow_state' => 'draft',
        'created_by_role' => 'admin',
    ]);

    foreach ([3, 4] as $qty) {
        InventoryCheckItem::query()->create([
            'inventory_check_id' => $check->id,
            'product_id' => null,
            'sku' => 'DUP-SKU',
            'match_status' => 'matched',
            'quantity_in_store' => $qty,
        ]);
    }

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/apply-inventory-check", [
        'inventory_check_id' => $check->uuid,
    ]);
    $res->assertOk();
    $res->assertJsonPath('data.reset.qty_received_cleared', 1);

    $item = PurchaseOrderItem::query()->where('purchase_order_id', '=', $po->id)->firstOrFail();
    expect((int) $item->qty_received)->toBe(7);
});

it('clears po-linked lots and movements then applies check qty to lines that match', function (): void {
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130011',
        'sku' => 'LOT-SKU',
        'description' => 'P',
        'vendor' => 'Plamod',
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130003',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $withLot = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'LOT-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 5,
        'qty_received' => 5,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => null,
        'sku' => 'MISSING-ON-CHECK',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
        'qty_received' => 0,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => null,
        'sku' => '   ',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
        'qty_received' => 0,
    ]);

    $lot = InventoryLot::query()->create([
        'product_id' => $product->id,
        'purchase_order_item_id' => $withLot->id,
        'source_type' => 'po',
        'unit_cost' => '1.0000',
        'qty_received' => 5,
        'qty_remaining' => 5,
        'received_at' => now(),
    ]);

    InventoryMovement::query()->create([
        'product_id' => $product->id,
        'inventory_lot_id' => $lot->id,
        'kind' => 'deduct',
        'qty_delta' => -1,
        'reference_type' => 'manual',
        'reference_uuid' => null,
        'occurred_at' => now(),
    ]);

    $check = InventoryCheck::query()->create([
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeee130003',
        'source' => 'employee_scan',
        'workflow_state' => 'applied',
        'created_by_role' => 'employee',
    ]);

    InventoryCheckItem::query()->create([
        'inventory_check_id' => $check->id,
        'product_id' => null,
        'sku' => 'LOT-SKU',
        'match_status' => 'matched',
        'quantity_in_store' => 9,
    ]);
    InventoryCheckItem::query()->create([
        'inventory_check_id' => $check->id,
        'product_id' => null,
        'sku' => 'ONLY-ON-CHECK',
        'match_status' => 'unmatched',
        'quantity_in_store' => 2,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/apply-inventory-check", [
        'inventory_check_id' => $check->uuid,
    ]);

    $res->assertOk();
    $res->assertJsonPath('data.lines_updated', 1);
    $res->assertJsonPath('data.reset.movements_deleted', 1);
    $res->assertJsonPath('data.reset.lots_deleted', 1);
    $res->assertJsonPath('data.reset.qty_received_cleared', 3);

    $warnings = $res->json('data.warnings');
    expect($warnings)->toBeArray();

    $kinds = array_values(array_unique(array_map(static fn ($w) => $w['kind'] ?? '', $warnings)));
    sort($kinds);
    expect($kinds)->toContain('po_line_no_inventory_match');
    expect($kinds)->toContain('check_sku_not_on_po');
    expect($kinds)->toContain('po_line_empty_sku');
    expect($kinds)->not->toContain('qty_received_has_lots');

    $withLot->refresh();
    expect((int) $withLot->qty_received)->toBe(9);

    expect(InventoryLot::query()->where('purchase_order_item_id', '=', $withLot->id)->count())->toBe(0);
    expect(InventoryMovement::query()->where('inventory_lot_id', '=', $lot->id)->count())->toBe(0);
});

it('validates inventory_check_id', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130004',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/apply-inventory-check", [])
        ->assertStatus(422);
});
