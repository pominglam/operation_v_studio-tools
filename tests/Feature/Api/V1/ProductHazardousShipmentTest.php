<?php

declare(strict_types=1);

use App\Models\Product;

it('updates product hazardous shipment flag', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000083101',
        'sku' => 'HAZARD-1',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'is_hazardous_shipment' => false,
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}/hazardous-shipment", [
        'is_hazardous_shipment' => true,
    ]);
    $res->assertOk()
        ->assertJsonPath('data.id', (string) $p->uuid)
        ->assertJsonPath('data.is_hazardous_shipment', true);

    $p->refresh();
    expect($p->is_hazardous_shipment)->toBeTrue();
});

it('validates product hazardous shipment payload', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000083102',
        'sku' => 'HAZARD-2',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'is_hazardous_shipment' => false,
    ]);

    $this->patchJson("/api/v1/products/{$p->uuid}/hazardous-shipment", [
        'is_hazardous_shipment' => 'not-a-bool',
    ])->assertStatus(422);
});
