<?php

declare(strict_types=1);

use App\Models\Product;

it('updates a product by uuid', function (): void {
    $product = Product::query()->create([
        'sku' => 'UP-1',
        'description' => 'Before',
        'barcode' => '111',
    ]);

    $response = $this->patchJson("/api/v1/products/{$product->uuid}", [
        'sku' => 'UP-1A',
        'barcode' => '222',
        'description' => 'After',
        'type' => 'HG',
        'price' => 12.34,
        'order' => 3,
        'filled' => 2,
        'extended' => 37.02,
    ]);

    $response->assertOk()->assertJsonPath('data.sku', 'UP-1A');

    $this->assertDatabaseHas('products', [
        'uuid' => $product->uuid,
        'sku' => 'UP-1A',
        'barcode' => '222',
        'description' => 'After',
        'type' => 'HG',
    ]);
});

it('validates required fields when updating a product', function (): void {
    $product = Product::query()->create([
        'sku' => 'UP-2',
        'description' => 'Before',
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}", [
        'description' => 'Only description',
    ])->assertStatus(422);
});

it('rejects duplicate sku when updating a product', function (): void {
    $a = Product::query()->create([
        'sku' => 'UP-A',
        'description' => 'A',
    ]);
    $b = Product::query()->create([
        'sku' => 'UP-B',
        'description' => 'B',
    ]);

    $response = $this->patchJson("/api/v1/products/{$b->uuid}", [
        'sku' => $a->sku,
        'description' => 'B updated',
    ]);

    $response->assertStatus(409)->assertJson([
        'message' => 'SKU already exists.',
    ]);
});
