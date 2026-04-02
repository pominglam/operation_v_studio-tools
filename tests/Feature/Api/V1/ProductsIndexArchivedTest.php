<?php

declare(strict_types=1);

use App\Models\Product;

it('hides archived products by default, and includes them when include_archived=1', function (): void {
    $active = Product::query()->create([
        'sku' => 'IDX-ACTIVE-1',
        'description' => 'Active product',
    ]);

    $archived = Product::query()->create([
        'sku' => 'IDX-ARCH-1',
        'description' => 'Archived product',
        'archived_at' => now(),
    ]);

    $resDefault = $this->getJson('/api/v1/products?per_page=500');
    $resDefault->assertOk();
    $idsDefault = collect($resDefault->json('data'))->pluck('id')->all();
    expect($idsDefault)->toContain($active->uuid);
    expect($idsDefault)->not->toContain($archived->uuid);

    $resIncl = $this->getJson('/api/v1/products?per_page=500&include_archived=1');
    $resIncl->assertOk();
    $idsIncl = collect($resIncl->json('data'))->pluck('id')->all();
    expect($idsIncl)->toContain($active->uuid);
    expect($idsIncl)->toContain($archived->uuid);
});

