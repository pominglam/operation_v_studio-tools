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

it('filters purchase orders index by derived statuses query param', function (): void {
    $draft = PurchaseOrder::query()->create([
        'vendor' => 'StatusVendor',
        'ordered_date' => null,
        'shipped_date' => null,
        'received_date' => null,
        'fully_on_shelves_date' => null,
    ]);
    $received = PurchaseOrder::query()->create([
        'vendor' => 'StatusVendor',
        'received_date' => '2026-02-01',
        'fully_on_shelves_date' => null,
    ]);
    $onShelves = PurchaseOrder::query()->create([
        'vendor' => 'StatusVendor',
        'fully_on_shelves_date' => '2026-02-02',
    ]);

    $res = $this->getJson('/api/v1/purchase-orders?per_page=100&statuses[]=draft&statuses[]=on_shelves');
    $res->assertOk();

    $ids = array_map(static fn (array $row): string => (string) $row['id'], $res->json('data') ?? []);
    expect($ids)->toContain((string) $draft->uuid);
    expect($ids)->toContain((string) $onShelves->uuid);
    expect($ids)->not->toContain((string) $received->uuid);
});
