<?php

declare(strict_types=1);

use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Models\Product;

it('patches an inventory check line with 204 and updates stored values', function (): void {
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000a9001',
        'sku' => 'ICU-PATCH-1',
        'barcode' => 'ICU90001',
        'description' => 'Desc',
        'handle' => 'h-icu-1',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'available_qty' => 4,
    ]);

    $check = InventoryCheck::query()->create([
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
        'name' => null,
        'source' => 'csv_import',
        'workflow_state' => 'draft',
        'created_by_role' => 'admin',
    ]);

    $item = InventoryCheckItem::query()->create([
        'inventory_check_id' => $check->id,
        'product_id' => $product->id,
        'handle' => 'h-icu-1',
        'vendor' => 'Plamod',
        'sku' => 'ICU-PATCH-1',
        'type' => 'HG',
        'product_name' => 'Old name',
        'english_name' => 'Old EN',
        'available_amount' => 4,
        'quantity_in_store' => 1,
        'difference' => -3,
        'match_status' => 'matched',
        'applied' => false,
    ]);

    $res = $this->patchJson("/api/v1/inventory-check/{$check->uuid}/items/{$item->id}", [
        'quantity' => 7,
        'product_name' => 'Updated name',
    ]);

    $res->assertNoContent();

    $item->refresh();
    expect($item->quantity_in_store)->toBe(7);
    expect($item->difference)->toBe(3);
    expect($item->product_name)->toBe('Updated name');
    expect($item->applied)->toBeFalse();

    $check->refresh();
    expect($check->workflow_state)->toBe('ready_for_review');
});

it('returns 409 when the inventory check session is already applied', function (): void {
    $check = InventoryCheck::query()->create([
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0002',
        'name' => null,
        'source' => 'csv_import',
        'workflow_state' => 'applied',
        'created_by_role' => 'admin',
        'applied_at' => now(),
    ]);

    $item = InventoryCheckItem::query()->create([
        'inventory_check_id' => $check->id,
        'product_id' => null,
        'sku' => 'X',
        'match_status' => 'unmatched',
        'quantity_in_store' => 0,
        'applied' => false,
    ]);

    $this->patchJson("/api/v1/inventory-check/{$check->uuid}/items/{$item->id}", [
        'quantity' => 1,
    ])->assertStatus(409);
});

it('assigns an unmatched inventory check line to an existing product', function (): void {
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000a9011',
        'sku' => 'ICU-ASSIGN-1',
        'barcode' => 'ICU90111',
        'description' => 'Assigned product',
        'handle' => 'h-icu-assigned',
        'type' => 'Paint',
        'vendor' => 'Dspiae',
        'available_qty' => 5,
        'latest_landed_unit_cost' => '3.21',
    ]);

    $check = InventoryCheck::query()->create([
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0011',
        'name' => null,
        'source' => 'employee_scan',
        'workflow_state' => 'draft',
        'created_by_role' => 'employee',
    ]);

    $item = InventoryCheckItem::query()->create([
        'inventory_check_id' => $check->id,
        'product_id' => null,
        'barcode_scanned' => '6978680760429',
        'sku' => '6978680760429',
        'product_name' => 'XSM - 001 - Metallic Red',
        'quantity_in_store' => 3,
        'available_amount' => null,
        'difference' => null,
        'match_status' => 'unmatched',
        'match_error' => 'No active product found for barcode.',
        'issue_flag' => true,
        'issue_reason' => 'Product not found or archived.',
        'applied' => false,
    ]);

    $res = $this->postJson("/api/v1/inventory-check/{$check->uuid}/items/{$item->id}/assign-product", [
        'product_id' => $product->uuid,
    ]);

    $res->assertOk()->assertJson(['message' => 'Inventory check line assigned.']);

    $item->refresh();
    expect($item->product_id)->toBe($product->id);
    expect($item->barcode_scanned)->toBe('6978680760429');
    expect($item->sku)->toBe('ICU-ASSIGN-1');
    expect($item->handle)->toBe('h-icu-assigned');
    expect($item->vendor)->toBe('Dspiae');
    expect($item->type)->toBe('Paint');
    expect($item->product_name)->toBe('Assigned product');
    expect($item->available_amount)->toBe(5);
    expect($item->difference)->toBe(-2);
    expect($item->match_status)->toBe('matched');
    expect($item->match_error)->toBeNull();
    expect($item->issue_flag)->toBeFalse();
    expect($item->issue_reason)->toBeNull();
    expect($item->applied)->toBeFalse();

    $check->refresh();
    expect($check->workflow_state)->toBe('ready_for_review');
});

it('returns 409 when assigning a product to an applied inventory check session', function (): void {
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000a9012',
        'sku' => 'ICU-ASSIGN-2',
        'barcode' => 'ICU90112',
        'description' => 'Assigned product 2',
        'handle' => 'h-icu-assigned-2',
        'type' => 'Paint',
        'vendor' => 'Dspiae',
        'available_qty' => 5,
    ]);

    $check = InventoryCheck::query()->create([
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0012',
        'name' => null,
        'source' => 'employee_scan',
        'workflow_state' => 'applied',
        'created_by_role' => 'employee',
        'applied_at' => now(),
    ]);

    $item = InventoryCheckItem::query()->create([
        'inventory_check_id' => $check->id,
        'product_id' => null,
        'sku' => '6978680760429',
        'quantity_in_store' => 3,
        'match_status' => 'unmatched',
        'match_error' => 'No active product found for barcode.',
        'applied' => false,
    ]);

    $this->postJson("/api/v1/inventory-check/{$check->uuid}/items/{$item->id}/assign-product", [
        'product_id' => $product->uuid,
    ])->assertStatus(409);
});
