<?php

declare(strict_types=1);

use App\Models\Product;

it('updates product ready flag', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000080001',
        'sku' => 'READY-FLAG-1',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'is_ready' => false,
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}/ready", ['is_ready' => true]);
    $res->assertOk()
        ->assertJsonPath('data.id', (string) $p->uuid)
        ->assertJsonPath('data.is_ready', true);

    $p->refresh();
    expect($p->is_ready)->toBeTrue();
});

it('validates product ready payload', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000080002',
        'sku' => 'READY-FLAG-2',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'is_ready' => false,
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}/ready", ['is_ready' => 'not-a-bool']);
    $res->assertStatus(422);
});
