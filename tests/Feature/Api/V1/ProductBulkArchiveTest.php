<?php

declare(strict_types=1);

use App\Models\Product;

it('archives selected products by uuid', function (): void {
    $p1 = Product::query()->create([
        'sku' => 'ARCH-1',
        'description' => 'Archive 1',
    ]);
    $p2 = Product::query()->create([
        'sku' => 'ARCH-2',
        'description' => 'Archive 2',
    ]);

    $this->postJson('/api/v1/products/bulk-archive', [
        'ids' => [$p1->uuid],
        'archived' => true,
    ])->assertOk()->assertJson([
        'updated' => 1,
    ]);

    $this->assertDatabaseHas('products', ['uuid' => $p1->uuid]);
    expect(Product::query()->where('uuid', $p1->uuid)->value('archived_at'))->not->toBeNull();
    expect(Product::query()->where('uuid', $p2->uuid)->value('archived_at'))->toBeNull();
});

it('unarchives selected products by uuid', function (): void {
    $p = Product::query()->create([
        'sku' => 'UNARCH-1',
        'description' => 'Unarchive 1',
        'archived_at' => now(),
    ]);

    $this->postJson('/api/v1/products/bulk-archive', [
        'ids' => [$p->uuid],
        'archived' => false,
    ])->assertOk()->assertJson([
        'updated' => 1,
    ]);

    expect(Product::query()->where('uuid', $p->uuid)->value('archived_at'))->toBeNull();
});
