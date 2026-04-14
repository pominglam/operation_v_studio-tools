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
