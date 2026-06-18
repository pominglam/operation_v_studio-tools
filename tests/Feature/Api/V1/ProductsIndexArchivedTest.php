<?php

declare(strict_types=1);

use App\Models\Product;

it('hides archived products by default, includes them when archived=all, and shows only archived when archived=archived', function (): void {
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

    $resAll = $this->getJson('/api/v1/products?per_page=500&archived=all');
    $resAll->assertOk();
    $idsAll = collect($resAll->json('data'))->pluck('id')->all();
    expect($idsAll)->toContain($active->uuid);
    expect($idsAll)->toContain($archived->uuid);

    $resLegacy = $this->getJson('/api/v1/products?per_page=500&include_archived=1');
    $resLegacy->assertOk();
    $idsLegacy = collect($resLegacy->json('data'))->pluck('id')->all();
    expect($idsLegacy)->toContain($active->uuid);
    expect($idsLegacy)->toContain($archived->uuid);

    $resArchivedOnly = $this->getJson('/api/v1/products?per_page=500&archived=archived');
    $resArchivedOnly->assertOk();
    $idsArchivedOnly = collect($resArchivedOnly->json('data'))->pluck('id')->all();
    expect($idsArchivedOnly)->not->toContain($active->uuid);
    expect($idsArchivedOnly)->toContain($archived->uuid);
    expect(collect($resArchivedOnly->json('data'))->every(fn (array $row): bool => ($row['is_archived'] ?? false) === true))->toBeTrue();
});
