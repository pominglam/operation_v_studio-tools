<?php

declare(strict_types=1);

use App\Models\Product;

it('bulk updates selected products by uuid', function (): void {
    $p1 = Product::query()->create([
        'sku' => 'BULK-UP-1',
        'description' => 'Bulk Up 1',
        'vendor' => 'Plamod',
        'type' => 'Old',
        'published_on_shopify' => false,
    ]);
    $p2 = Product::query()->create([
        'sku' => 'BULK-UP-2',
        'description' => 'Bulk Up 2',
        'vendor' => 'Plamod',
        'type' => 'Old',
        'published_on_shopify' => false,
    ]);

    $response = $this->postJson('/api/v1/products/bulk-update', [
        'ids' => [$p1->uuid, $p2->uuid],
        'changes' => [
            'vendor' => 'MSMN',
            'type' => 'New',
            'published_on_shopify' => true,
            'price' => 12.34,
            'order' => 5,
            'filled' => 2,
        ],
    ]);

    $response->assertOk()->assertJson([
        'updated' => 2,
    ]);

    $this->assertDatabaseHas('products', [
        'uuid' => $p1->uuid,
        'vendor' => 'MSMN',
        'type' => 'New',
        'published_on_shopify' => 1,
        'order_qty' => 5,
        'filled_qty' => 2,
    ]);

    $this->assertDatabaseHas('products', [
        'uuid' => $p2->uuid,
        'vendor' => 'MSMN',
        'type' => 'New',
        'published_on_shopify' => 1,
        'order_qty' => 5,
        'filled_qty' => 2,
    ]);
});

it('validates payload for bulk update', function (): void {
    $response = $this->postJson('/api/v1/products/bulk-update', [
        'ids' => [],
        'changes' => [],
    ]);

    $response->assertStatus(422);
});

it('returns 409 when bulk update violates sku uniqueness', function (): void {
    $p1 = Product::query()->create([
        'sku' => 'BULK-UP-SKU-1',
        'description' => 'Bulk Up Sku 1',
    ]);
    $p2 = Product::query()->create([
        'sku' => 'BULK-UP-SKU-2',
        'description' => 'Bulk Up Sku 2',
    ]);

    $response = $this->postJson('/api/v1/products/bulk-update', [
        'ids' => [$p2->uuid],
        'changes' => [
            'sku' => $p1->sku,
        ],
    ]);

    $response->assertStatus(409);
});



