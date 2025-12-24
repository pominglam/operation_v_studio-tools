<?php

declare(strict_types=1);

it('updates product barcode without requiring full product payload', function (): void {
    $product = \App\Models\Product::query()->create([
        'sku' => 'BC-1',
        'barcode' => null,
        'description' => 'Barcode test product',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'price' => '12.34',
        'order_qty' => 2,
        'filled_qty' => 1,
        'extended' => '24.68',
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}/barcode", [
        'barcode' => '4573102603586',
    ])->assertOk()
        ->assertJsonPath('data.id', $product->uuid)
        ->assertJsonPath('data.barcode', '4573102603586');

    $product->refresh();

    expect($product->barcode)->toBe('4573102603586')
        ->and($product->sku)->toBe('BC-1')
        ->and($product->type)->toBe('HG')
        ->and($product->vendor)->toBe('Plamod')
        ->and((string) $product->price)->toBe('12.34');
});

it('clears barcode when empty string is provided', function (): void {
    $product = \App\Models\Product::query()->create([
        'sku' => 'BC-2',
        'barcode' => '111',
        'description' => 'Barcode test product',
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}/barcode", [
        'barcode' => '',
    ])->assertOk()
        ->assertJsonPath('data.barcode', null);

    $product->refresh();
    expect($product->barcode)->toBeNull();
});


