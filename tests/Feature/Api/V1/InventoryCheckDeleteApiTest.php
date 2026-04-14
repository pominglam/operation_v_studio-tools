<?php

declare(strict_types=1);

use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Models\Product;

it('deletes an inventory check and its line items', function (): void {
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000a9002',
        'sku' => 'ICU-DEL-1',
        'barcode' => 'ICU90002',
        'description' => 'Desc',
        'handle' => 'h-icu-del',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'available_qty' => 1,
    ]);

    $check = InventoryCheck::query()->create([
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0002',
        'name' => null,
        'source' => 'employee_scan',
        'workflow_state' => 'draft',
        'created_by_role' => 'employee',
    ]);

    InventoryCheckItem::query()->create([
        'inventory_check_id' => $check->id,
        'product_id' => $product->id,
        'handle' => 'h-icu-del',
        'vendor' => 'Plamod',
        'sku' => 'ICU-DEL-1',
        'type' => 'HG',
        'product_name' => 'N',
        'english_name' => 'N',
        'available_amount' => 1,
        'quantity_in_store' => 0,
        'difference' => -1,
        'match_status' => 'matched',
        'applied' => false,
    ]);

    $this->deleteJson("/api/v1/inventory-check/{$check->uuid}")
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(InventoryCheck::query()->where('id', '=', $check->id)->exists())->toBeFalse();
    expect(InventoryCheckItem::query()->where('inventory_check_id', '=', $check->id)->count())->toBe(0);

    $this->getJson("/api/v1/inventory-check/{$check->uuid}")->assertNotFound();
});

it('returns 404 when deleting unknown inventory check uuid', function (): void {
    $this->deleteJson('/api/v1/inventory-check/00000000-0000-0000-0000-000000000099')->assertNotFound();
});
