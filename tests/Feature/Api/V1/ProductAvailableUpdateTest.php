<?php

declare(strict_types=1);

it('updates product available quantity without requiring full product payload', function (): void {
    $product = \App\Models\Product::query()->create([
        'sku' => 'AVAIL-UPD-1',
        'barcode' => null,
        'description' => 'Available update test product',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'latest_unit_cost' => '12.34',
        'order_qty' => 2,
        'filled_qty' => 1,
        'available_qty' => 5,
        'extended' => '24.68',
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}/available", [
        'available' => 7,
    ])->assertOk()
        ->assertJsonPath('data.id', $product->uuid)
        ->assertJsonPath('data.available', 7);

    $product->refresh();

    expect($product->available_qty)->toBe(7)
        ->and($product->sku)->toBe('AVAIL-UPD-1')
        ->and($product->barcode)->toBeNull()
        ->and($product->type)->toBe('HG')
        ->and($product->vendor)->toBe('Plamod')
        ->and((string) $product->latest_unit_cost)->toBe('12.34');
});
