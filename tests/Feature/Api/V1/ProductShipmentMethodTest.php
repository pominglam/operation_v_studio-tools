<?php

declare(strict_types=1);

use App\Models\Product;

it('updates product shipment method', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000084101',
        'sku' => 'SHIP-1',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'shipment_method' => null,
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}/shipment-method", [
        'shipment_method' => 'air',
    ]);
    $res->assertOk()
        ->assertJsonPath('data.id', (string) $p->uuid)
        ->assertJsonPath('data.shipment_method', 'air');

    $p->refresh();
    expect($p->shipment_method)->toBe('air');
});

it('clears product shipment method', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000084102',
        'sku' => 'SHIP-2',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'shipment_method' => 'sea',
    ]);

    $this->patchJson("/api/v1/products/{$p->uuid}/shipment-method", [
        'shipment_method' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.shipment_method', null);

    $p->refresh();
    expect($p->shipment_method)->toBeNull();
});

it('validates product shipment method payload', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000084103',
        'sku' => 'SHIP-3',
        'description' => 'Test product',
        'vendor' => 'Plamod',
    ]);

    $this->patchJson("/api/v1/products/{$p->uuid}/shipment-method", [
        'shipment_method' => 'truck',
    ])->assertStatus(422);
});
