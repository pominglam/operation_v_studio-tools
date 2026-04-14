<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;

it('filters purchase orders index by vendors query param', function (): void {
    $plamod = PurchaseOrder::query()->create(['vendor' => 'Plamod']);
    $dspiae = PurchaseOrder::query()->create(['vendor' => 'Dspiae']);
    $other = PurchaseOrder::query()->create(['vendor' => 'OtherCo']);

    $res = $this->getJson('/api/v1/purchase-orders?per_page=50&vendors[]=Plamod&vendors[]=Dspiae');
    $res->assertOk();

    $ids = array_map(static fn (array $row): string => (string) $row['id'], $res->json('data') ?? []);
    expect($ids)->toContain((string) $plamod->uuid);
    expect($ids)->toContain((string) $dspiae->uuid);
    expect($ids)->not->toContain((string) $other->uuid);
});

it('returns all purchase orders when vendors filter is empty or omitted', function (): void {
    PurchaseOrder::query()->create(['vendor' => 'OnlyOne']);

    $all = $this->getJson('/api/v1/purchase-orders?per_page=50');
    $all->assertOk();
    expect(count($all->json('data') ?? []))->toBeGreaterThanOrEqual(1);

    $empty = $this->getJson('/api/v1/purchase-orders?per_page=50&vendors=');
    $empty->assertOk();
    expect(count($empty->json('data') ?? []))->toBeGreaterThanOrEqual(1);
});
