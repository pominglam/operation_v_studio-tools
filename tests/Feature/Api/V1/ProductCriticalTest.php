<?php

declare(strict_types=1);

use App\Models\Product;

it('updates product critical flag', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000082001',
        'sku' => 'CRITICAL-1',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'is_critical' => false,
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}/critical", ['is_critical' => true]);
    $res->assertOk()
        ->assertJsonPath('data.id', (string) $p->uuid)
        ->assertJsonPath('data.is_critical', true);

    $p->refresh();
    expect($p->is_critical)->toBeTrue();
});

it('validates product critical payload', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000082002',
        'sku' => 'CRITICAL-2',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'is_critical' => false,
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}/critical", ['is_critical' => 'not-a-bool']);
    $res->assertStatus(422);
});
