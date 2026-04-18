<?php

declare(strict_types=1);

it('updates product filled (shipped) quantity without requiring full product payload', function (): void {
    $product = \App\Models\Product::query()->create([
        'sku' => 'FILL-1',
        'barcode' => null,
        'description' => 'Filled test product',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'latest_unit_cost' => '12.34',
        'order_qty' => 2,
        'filled_qty' => 1,
        'extended' => '24.68',
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}/filled", [
        'filled' => 7,
    ])->assertOk()
        ->assertJsonPath('data.id', $product->uuid)
        ->assertJsonPath('data.filled', 7);

    $product->refresh();

    expect($product->filled_qty)->toBe(7)
        ->and($product->sku)->toBe('FILL-1')
        ->and($product->barcode)->toBeNull()
        ->and($product->type)->toBe('HG')
        ->and($product->vendor)->toBe('Plamod')
        ->and((string) $product->latest_unit_cost)->toBe('12.34');
});
