<?php

declare(strict_types=1);

use App\Models\Product;

it('bulk updates selected products by uuid', function (): void {
    $p1 = Product::query()->create([
        'sku' => 'BULK-UP-1',
        'description' => 'Bulk Up 1',
        'vendor' => 'Plamod',
        'type' => 'Old',
        'grade' => null,
        'scale' => null,
        'series' => null,
        'published_on_shopify' => false,
    ]);
    $p2 = Product::query()->create([
        'sku' => 'BULK-UP-2',
        'description' => 'Bulk Up 2',
        'vendor' => 'Plamod',
        'type' => 'Old',
        'grade' => null,
        'scale' => null,
        'series' => null,
        'published_on_shopify' => false,
    ]);

    $response = $this->postJson('/api/v1/products/bulk-update', [
        'ids' => [$p1->uuid, $p2->uuid],
        'changes' => [
            'main_type' => 'tools',
            'vendor' => 'MSMN',
            'type' => 'New',
            'grade' => 'RG',
            'scale' => '1/144',
            'series' => 'Gundam Seed',
            'published_on_shopify' => true,
            'archived' => true,
            'price' => 12.34,
            'order' => 5,
            'filled' => 2,
            'available' => 0,
            'maintain' => 8,
        ],
    ]);

    $response->assertOk()->assertJson([
        'updated' => 2,
    ]);

    $this->assertDatabaseHas('products', [
        'uuid' => $p1->uuid,
        'main_type' => 'tools',
        'vendor' => 'MSMN',
        'type' => 'New',
        'grade' => 'RG',
        'scale' => '1/144',
        'series' => 'Gundam Seed',
        'published_on_shopify' => 1,
        'order_qty' => 5,
        'filled_qty' => 2,
        'available_qty' => 0,
        'maintain_qty' => 8,
    ]);
    expect(Product::query()->where('uuid', $p1->uuid)->value('archived_at'))->not->toBeNull();

    $this->assertDatabaseHas('products', [
        'uuid' => $p2->uuid,
        'main_type' => 'tools',
        'vendor' => 'MSMN',
        'type' => 'New',
        'grade' => 'RG',
        'scale' => '1/144',
        'series' => 'Gundam Seed',
        'published_on_shopify' => 1,
        'order_qty' => 5,
        'filled_qty' => 2,
        'available_qty' => 0,
        'maintain_qty' => 8,
    ]);
    expect(Product::query()->where('uuid', $p2->uuid)->value('archived_at'))->not->toBeNull();
});

it('bulk update can unarchive selected products by uuid', function (): void {
    $p1 = Product::query()->create([
        'sku' => 'BULK-UP-UNARCH-1',
        'description' => 'Bulk Up Unarchive 1',
        'archived_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/products/bulk-update', [
        'ids' => [$p1->uuid],
        'changes' => [
            'archived' => false,
        ],
    ]);

    $response->assertOk()->assertJson([
        'updated' => 1,
    ]);

    expect(Product::query()->where('uuid', $p1->uuid)->value('archived_at'))->toBeNull();
});

it('validates payload for bulk update', function (): void {
    $response = $this->postJson('/api/v1/products/bulk-update', [
        'ids' => [],
        'changes' => [],
    ]);

    $response->assertStatus(422);

    $badArchived = $this->postJson('/api/v1/products/bulk-update', [
        'ids' => [Product::query()->create([
            'sku' => 'BULK-UP-INVALID-ARCHIVED',
            'description' => 'Bulk invalid archived',
        ])->uuid],
        'changes' => [
            'archived' => 'invalid',
        ],
    ]);
    $badArchived->assertStatus(422);
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
