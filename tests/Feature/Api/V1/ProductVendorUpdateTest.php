<?php

declare(strict_types=1);

it('updates product vendor without requiring full product payload', function (): void {
    $product = \App\Models\Product::query()->create([
        'sku' => 'VENDOR-UPD-1',
        'description' => 'Vendor update test product',
        'type' => 'Tools',
        'vendor' => null,
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}/vendor", [
        'vendor' => 'Stedi',
    ])->assertOk()
        ->assertJsonPath('data.id', $product->uuid)
        ->assertJsonPath('data.vendor', 'Stedi');

    $product->refresh();

    expect($product->vendor)->toBe('Stedi');
});

it('clears product vendor when blank string is sent', function (): void {
    $product = \App\Models\Product::query()->create([
        'sku' => 'VENDOR-CLR-1',
        'description' => 'Vendor clear test product',
        'type' => 'Tools',
        'vendor' => 'Dspiae',
    ]);

    $this->patchJson("/api/v1/products/{$product->uuid}/vendor", [
        'vendor' => '',
    ])->assertOk()
        ->assertJsonPath('data.vendor', null);

    $product->refresh();

    expect($product->vendor)->toBeNull();
});
