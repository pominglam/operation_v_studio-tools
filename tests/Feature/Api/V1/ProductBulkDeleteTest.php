<?php

declare(strict_types=1);

use App\Models\Product;

it('deletes selected products by uuid', function (): void {
    $p1 = Product::query()->create([
        'sku' => 'BULK-1',
        'description' => 'Bulk 1',
    ]);
    $p2 = Product::query()->create([
        'sku' => 'BULK-2',
        'description' => 'Bulk 2',
    ]);

    $response = $this->postJson('/api/v1/products/bulk-delete', [
        'ids' => [$p1->uuid],
    ]);

    $response->assertOk()->assertJson([
        'deleted' => 1,
    ]);

    $this->assertDatabaseMissing('products', ['sku' => 'BULK-1']);
    $this->assertDatabaseHas('products', ['sku' => 'BULK-2']);
});

it('validates ids for bulk delete', function (): void {
    $response = $this->postJson('/api/v1/products/bulk-delete', [
        'ids' => [],
    ]);

    $response->assertStatus(422);
});
