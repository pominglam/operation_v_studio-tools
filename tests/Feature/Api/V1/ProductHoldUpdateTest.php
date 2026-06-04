<?php

declare(strict_types=1);

use App\Models\Product;

it('updates product hold quantity without requiring full product payload', function (): void {
    $product = Product::query()->create([
        'sku' => 'HOLD-UPD-1',
        'description' => 'Hold update test product',
        'available_qty' => 10,
        'hold_qty' => 0,
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}/hold", [
        'hold' => 4,
    ])->assertOk()
        ->assertJsonPath('data.id', $product->uuid)
        ->assertJsonPath('data.hold', 4);

    $product->refresh();
    expect($product->hold_qty)->toBe(4);
});

it('rejects hold greater than available quantity', function (): void {
    $product = Product::query()->create([
        'sku' => 'HOLD-REJ-1',
        'description' => 'Hold reject test',
        'available_qty' => 3,
        'hold_qty' => 1,
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}/hold", [
        'hold' => 5,
    ])->assertStatus(422);

    $product->refresh();
    expect($product->hold_qty)->toBe(1);
});

it('rejects available quantity below existing hold', function (): void {
    $product = Product::query()->create([
        'sku' => 'HOLD-AVAIL-1',
        'description' => 'Available below hold',
        'available_qty' => 8,
        'hold_qty' => 5,
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}/available", [
        'available' => 3,
    ])->assertStatus(422);

    $product->refresh();
    expect($product->available_qty)->toBe(8);
});
