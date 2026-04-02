<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;

it('returns distinct purchase order vendors', function (): void {
    PurchaseOrder::query()->create(['vendor' => 'Plamod']);
    PurchaseOrder::query()->create(['vendor' => '  Gundam Planet  ']);
    PurchaseOrder::query()->create(['vendor' => 'plamod']);
    PurchaseOrder::query()->create(['vendor' => '']);

    $res = $this->getJson('/api/v1/purchase-orders/filter-options');
    $res->assertOk();

    $vendors = $res->json('data.vendors') ?? [];
    expect($vendors)->toContain('Gundam Planet');
    $lower = array_map(static fn (string $v): string => strtolower(trim($v)), $vendors);
    expect($lower)->toContain('plamod');
});

