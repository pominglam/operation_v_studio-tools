<?php

declare(strict_types=1);

use App\Models\Product;

it('updates product latest arrival flag', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000081001',
        'sku' => 'LATEST-ARRIVAL-1',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'latest_arrival' => false,
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}/latest-arrival", ['latest_arrival' => true]);
    $res->assertOk()
        ->assertJsonPath('data.id', (string) $p->uuid)
        ->assertJsonPath('data.latest_arrival', true);

    $p->refresh();
    expect($p->latest_arrival)->toBeTrue();
});

it('validates product latest arrival payload', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000081002',
        'sku' => 'LATEST-ARRIVAL-2',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'latest_arrival' => false,
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}/latest-arrival", ['latest_arrival' => 'not-a-bool']);
    $res->assertStatus(422);
});
