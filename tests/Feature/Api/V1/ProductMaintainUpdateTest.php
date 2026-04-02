<?php

declare(strict_types=1);

it('updates product maintain quantity without requiring full product payload', function (): void {
    $product = \App\Models\Product::query()->create([
        'sku' => 'MAINTAIN-UPD-1',
        'barcode' => null,
        'description' => 'Maintain update test product',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'latest_unit_cost' => '12.34',
        'order_qty' => 2,
        'filled_qty' => 1,
        'available_qty' => 5,
        'maintain_qty' => 3,
        'extended' => '24.68',
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}/maintain", [
        'maintain' => 9,
    ])->assertOk()
        ->assertJsonPath('data.id', $product->uuid)
        ->assertJsonPath('data.maintain', 9);

    $product->refresh();

    expect($product->maintain_qty)->toBe(9)
        ->and($product->available_qty)->toBe(5)
        ->and($product->sku)->toBe('MAINTAIN-UPD-1')
        ->and($product->barcode)->toBeNull()
        ->and($product->type)->toBe('HG')
        ->and($product->vendor)->toBe('Plamod')
        ->and((string) $product->latest_unit_cost)->toBe('12.34');
});
