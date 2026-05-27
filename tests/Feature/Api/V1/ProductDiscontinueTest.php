<?php

declare(strict_types=1);

use App\Models\Product;

it('updates product discontinue flag', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000082101',
        'sku' => 'DISCONTINUE-1',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'is_discontinued' => false,
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}/discontinue", ['is_discontinued' => true]);
    $res->assertOk()
        ->assertJsonPath('data.id', (string) $p->uuid)
        ->assertJsonPath('data.is_discontinued', true);

    $p->refresh();
    expect($p->is_discontinued)->toBeTrue();
});

it('validates product discontinue payload', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000082102',
        'sku' => 'DISCONTINUE-2',
        'description' => 'Test product',
        'vendor' => 'Plamod',
        'is_discontinued' => false,
    ]);

    $res = $this->patchJson("/api/v1/products/{$p->uuid}/discontinue", ['is_discontinued' => 'not-a-bool']);
    $res->assertStatus(422);
});
