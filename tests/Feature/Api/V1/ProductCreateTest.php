<?php

declare(strict_types=1);

it('creates a product', function (): void {
    $response = $this->postJson('/api/v1/products', [
        'sku' => 'SKU-1',
        'barcode' => '123',
        'description' => 'Test product',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'price' => 10.13,
        'order' => 2,
        'filled' => 1,
        'available' => 7,
        'extended' => 20.26,
    ]);

    $response->assertCreated()->assertJsonPath('data.sku', 'SKU-1');

    $this->assertDatabaseHas('products', [
        'sku' => 'SKU-1',
        'barcode' => '123',
        'description' => 'Test product',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'available_qty' => 7,
    ]);
});

it('validates required fields when creating a product', function (): void {
    $response = $this->postJson('/api/v1/products', []);

    $response->assertStatus(422);
});

it('rejects duplicate sku when creating a product', function (): void {
    $this->postJson('/api/v1/products', [
        'sku' => 'SKU-dup',
        'description' => 'A',
    ])->assertCreated();

    $response = $this->postJson('/api/v1/products', [
        'sku' => 'SKU-dup',
        'description' => 'B',
    ]);

    $response->assertStatus(409)->assertJson([
        'message' => 'SKU already exists.',
    ]);
});
