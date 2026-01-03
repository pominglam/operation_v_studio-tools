<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use Carbon\CarbonImmutable;

it('sorts purchase orders index by created_at via sort_dir', function (): void {
    $old = null;
    $new = null;

    PurchaseOrder::withoutTimestamps(function () use (&$old, &$new): void {
        $old = PurchaseOrder::query()->create(['vendor' => 'SortOld']);
        $old->forceFill([
            'created_at' => CarbonImmutable::parse('2025-12-01 00:00:00'),
            'updated_at' => CarbonImmutable::parse('2025-12-01 00:00:00'),
        ])->save();

        $new = PurchaseOrder::query()->create(['vendor' => 'SortNew']);
        $new->forceFill([
            'created_at' => CarbonImmutable::parse('2025-12-31 00:00:00'),
            'updated_at' => CarbonImmutable::parse('2025-12-31 00:00:00'),
        ])->save();
    });

    expect($old)->not->toBeNull();
    expect($new)->not->toBeNull();

    $desc = $this->getJson('/api/v1/purchase-orders?per_page=50&sort_dir=desc');
    $desc->assertOk();
    $descIds = array_map(static fn (array $row): string => (string) $row['id'], $desc->json('data') ?? []);
    $newIdx = array_search((string) $new?->uuid, $descIds, true);
    $oldIdx = array_search((string) $old?->uuid, $descIds, true);
    expect($newIdx)->not->toBeFalse();
    expect($oldIdx)->not->toBeFalse();
    expect((int) $newIdx)->toBeLessThan((int) $oldIdx);

    $asc = $this->getJson('/api/v1/purchase-orders?per_page=50&sort_dir=asc');
    $asc->assertOk();
    $ascIds = array_map(static fn (array $row): string => (string) $row['id'], $asc->json('data') ?? []);
    $newIdxAsc = array_search((string) $new?->uuid, $ascIds, true);
    $oldIdxAsc = array_search((string) $old?->uuid, $ascIds, true);
    expect($newIdxAsc)->not->toBeFalse();
    expect($oldIdxAsc)->not->toBeFalse();
    expect((int) $oldIdxAsc)->toBeLessThan((int) $newIdxAsc);
});

