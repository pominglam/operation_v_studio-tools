<?php

declare(strict_types=1);

use App\Models\Product;

it('persists available qty on product update', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000030001',
        'sku' => 'INV-001',
        'barcode' => null,
        'description' => 'Inventory test',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'price' => '10.00',
        'order_qty' => 1,
        'filled_qty' => 1,
        'available_qty' => null,
        'extended' => '10.00',
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}", [
        'sku' => $p->sku,
        'barcode' => $p->barcode,
        'description' => $p->description,
        'type' => $p->type,
        'vendor' => $p->vendor,
        'price' => $p->price,
        'order' => $p->order_qty,
        'filled' => $p->filled_qty,
        'available' => 7,
        'extended' => $p->extended,
    ]);

    $res->assertOk();
    $res->assertJsonPath('data.available', 7);
    $this->assertDatabaseHas('products', [
        'uuid' => $p->uuid,
        'available_qty' => 7,
    ]);
});

it('validates available qty must be non-negative', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000030002',
        'sku' => 'INV-002',
        'barcode' => null,
        'description' => 'Inventory test 2',
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}", [
        'sku' => $p->sku,
        'barcode' => $p->barcode,
        'description' => $p->description,
        'type' => null,
        'vendor' => null,
        'price' => null,
        'order' => null,
        'filled' => null,
        'available' => -1,
        'extended' => null,
    ]);

    $res->assertStatus(422);
    $res->assertJsonValidationErrors(['available']);
});


