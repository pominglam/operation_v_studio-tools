<?php

declare(strict_types=1);

use App\Models\InventoryCheck;
use App\Models\Product;
use Illuminate\Http\UploadedFile;

it('stores an optional session note when importing an inventory check CSV', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091001',
        'sku' => 'N-001',
        'barcode' => 'N001',
        'description' => 'Note test product',
        'handle' => 'note-handle-1',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'available_qty' => 1,
    ]);

    $csv = implode("\n", [
        'Handle,Vendor,SKU,Type,Product Name,English name,Available amount,Selling price,Quantity in store,Difference,Notes',
        'note-handle-1,Plamod,N-001,HG,Note test,,1,,2,1,Row note',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('inventory_check.csv', $csv);

    $res = $this->post('/api/v1/products/import-inventory-check', [
        'file' => $file,
        'notes' => '  Back wall paints  ',
    ]);

    $res->assertOk();

    /** @var string $uuid */
    $uuid = $res->json('inventory_check.uuid');
    $check = InventoryCheck::query()->where('uuid', '=', $uuid)->first();
    expect($check)->not->toBeNull();
    expect($check->notes)->toBe('Back wall paints');
});

it('updates an inventory check session note via PATCH', function (): void {
    $check = InventoryCheck::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091010',
        'source' => 'employee_scan',
        'notes' => 'Old note',
        'workflow_state' => 'draft',
    ]);

    $res = $this->patchJson("/api/v1/inventory-check/{$check->uuid}", [
        'notes' => 'Markers aisle + brushes',
    ]);

    $res->assertOk();
    $res->assertJsonPath('data.notes', 'Markers aisle + brushes');

    $check->refresh();
    expect($check->notes)->toBe('Markers aisle + brushes');
});

it('clears an inventory check session note when PATCH sends null', function (): void {
    $check = InventoryCheck::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091011',
        'source' => 'employee_scan',
        'notes' => 'To be cleared',
        'workflow_state' => 'draft',
    ]);

    $res = $this->patchJson("/api/v1/inventory-check/{$check->uuid}", [
        'notes' => '',
    ]);

    $res->assertOk();
    $res->assertJsonPath('data.notes', null);

    $check->refresh();
    expect($check->notes)->toBeNull();
});

it('returns 422 when session note exceeds max length on PATCH', function (): void {
    $check = InventoryCheck::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091012',
        'source' => 'employee_scan',
        'workflow_state' => 'draft',
    ]);

    $res = $this->patchJson("/api/v1/inventory-check/{$check->uuid}", [
        'notes' => str_repeat('x', 2001),
    ]);

    $res->assertStatus(422);
});

it('returns 404 when updating notes for an unknown inventory check session', function (): void {
    $res = $this->patchJson('/api/v1/inventory-check/00000000-0000-0000-0000-000000099999', [
        'notes' => 'Does not exist',
    ]);

    $res->assertNotFound();
});
