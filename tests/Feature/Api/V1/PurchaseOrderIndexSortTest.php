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

it('sorts purchase orders index by ordered_date via sort_by', function (): void {
    $oldOrdered = PurchaseOrder::query()->create([
        'vendor' => 'OrderedOld',
        'ordered_date' => '2026-01-01',
    ]);
    $newOrdered = PurchaseOrder::query()->create([
        'vendor' => 'OrderedNew',
        'ordered_date' => '2026-03-01',
    ]);
    $noOrdered = PurchaseOrder::query()->create([
        'vendor' => 'OrderedNone',
        'ordered_date' => null,
    ]);

    $desc = $this->getJson('/api/v1/purchase-orders?per_page=200&sort_by=ordered&sort_dir=desc');
    $desc->assertOk();
    $descIds = array_map(static fn (array $row): string => (string) $row['id'], $desc->json('data') ?? []);
    $newDescIdx = array_search((string) $newOrdered->uuid, $descIds, true);
    $oldDescIdx = array_search((string) $oldOrdered->uuid, $descIds, true);
    $noneDescIdx = array_search((string) $noOrdered->uuid, $descIds, true);
    expect($newDescIdx)->not->toBeFalse();
    expect($oldDescIdx)->not->toBeFalse();
    expect($noneDescIdx)->not->toBeFalse();
    expect((int) $newDescIdx)->toBeLessThan((int) $oldDescIdx);
    expect((int) $oldDescIdx)->toBeLessThan((int) $noneDescIdx);

    $asc = $this->getJson('/api/v1/purchase-orders?per_page=200&sort_by=ordered&sort_dir=asc');
    $asc->assertOk();
    $ascIds = array_map(static fn (array $row): string => (string) $row['id'], $asc->json('data') ?? []);
    $oldAscIdx = array_search((string) $oldOrdered->uuid, $ascIds, true);
    $newAscIdx = array_search((string) $newOrdered->uuid, $ascIds, true);
    $noneAscIdx = array_search((string) $noOrdered->uuid, $ascIds, true);
    expect($oldAscIdx)->not->toBeFalse();
    expect($newAscIdx)->not->toBeFalse();
    expect($noneAscIdx)->not->toBeFalse();
    expect((int) $oldAscIdx)->toBeLessThan((int) $newAscIdx);
    expect((int) $newAscIdx)->toBeLessThan((int) $noneAscIdx);
});

it('sorts purchase orders index by received_date via sort_by', function (): void {
    $oldReceived = PurchaseOrder::query()->create([
        'vendor' => 'RecvOld',
        'received_date' => '2026-01-01',
    ]);
    $newReceived = PurchaseOrder::query()->create([
        'vendor' => 'RecvNew',
        'received_date' => '2026-03-01',
    ]);
    $noReceived = PurchaseOrder::query()->create([
        'vendor' => 'RecvNone',
        'received_date' => null,
    ]);

    $desc = $this->getJson('/api/v1/purchase-orders?per_page=200&sort_by=received&sort_dir=desc');
    $desc->assertOk();
    $descIds = array_map(static fn (array $row): string => (string) $row['id'], $desc->json('data') ?? []);
    $newDescIdx = array_search((string) $newReceived->uuid, $descIds, true);
    $oldDescIdx = array_search((string) $oldReceived->uuid, $descIds, true);
    $noneDescIdx = array_search((string) $noReceived->uuid, $descIds, true);
    expect($newDescIdx)->not->toBeFalse();
    expect($oldDescIdx)->not->toBeFalse();
    expect($noneDescIdx)->not->toBeFalse();
    expect((int) $newDescIdx)->toBeLessThan((int) $oldDescIdx);
    expect((int) $oldDescIdx)->toBeLessThan((int) $noneDescIdx);

    $asc = $this->getJson('/api/v1/purchase-orders?per_page=200&sort_by=received&sort_dir=asc');
    $asc->assertOk();
    $ascIds = array_map(static fn (array $row): string => (string) $row['id'], $asc->json('data') ?? []);
    $oldAscIdx = array_search((string) $oldReceived->uuid, $ascIds, true);
    $newAscIdx = array_search((string) $newReceived->uuid, $ascIds, true);
    $noneAscIdx = array_search((string) $noReceived->uuid, $ascIds, true);
    expect($oldAscIdx)->not->toBeFalse();
    expect($newAscIdx)->not->toBeFalse();
    expect($noneAscIdx)->not->toBeFalse();
    expect((int) $oldAscIdx)->toBeLessThan((int) $newAscIdx);
    expect((int) $newAscIdx)->toBeLessThan((int) $noneAscIdx);
});

it('sorts purchase orders for product PO filter: not arrived by ETA desc then created, then arrived by received desc', function (): void {
    $openLateEta = PurchaseOrder::query()->create([
        'vendor' => 'FilterOpenLate',
        'received_date' => null,
        'estimated_arrival_date' => '2026-06-01',
    ]);
    $openEarlyEta = PurchaseOrder::query()->create([
        'vendor' => 'FilterOpenEarly',
        'received_date' => null,
        'estimated_arrival_date' => '2026-04-01',
    ]);
    $openNoEta = PurchaseOrder::query()->create([
        'vendor' => 'FilterOpenNoEta',
        'received_date' => null,
        'estimated_arrival_date' => null,
    ]);
    $recvNew = PurchaseOrder::query()->create([
        'vendor' => 'FilterRecvNew',
        'received_date' => '2026-05-01',
    ]);
    $recvOld = PurchaseOrder::query()->create([
        'vendor' => 'FilterRecvOld',
        'received_date' => '2026-01-01',
    ]);

    PurchaseOrder::withoutTimestamps(function () use ($openLateEta, $openEarlyEta, $openNoEta, $recvNew, $recvOld): void {
        $openLateEta->forceFill(['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'])->save();
        $openEarlyEta->forceFill(['created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00'])->save();
        $openNoEta->forceFill(['created_at' => '2026-01-03 00:00:00', 'updated_at' => '2026-01-03 00:00:00'])->save();
        $recvNew->forceFill(['created_at' => '2026-01-04 00:00:00', 'updated_at' => '2026-01-04 00:00:00'])->save();
        $recvOld->forceFill(['created_at' => '2026-01-05 00:00:00', 'updated_at' => '2026-01-05 00:00:00'])->save();
    });

    $res = $this->getJson('/api/v1/purchase-orders?per_page=200&sort_by=filter');
    $res->assertOk();
    $ids = array_map(static fn (array $row): string => (string) $row['id'], $res->json('data') ?? []);
    $iLate = array_search((string) $openLateEta->uuid, $ids, true);
    $iEarly = array_search((string) $openEarlyEta->uuid, $ids, true);
    $iNoEta = array_search((string) $openNoEta->uuid, $ids, true);
    $iRecvNew = array_search((string) $recvNew->uuid, $ids, true);
    $iRecvOld = array_search((string) $recvOld->uuid, $ids, true);
    expect($iLate)->not->toBeFalse();
    expect($iEarly)->not->toBeFalse();
    expect($iNoEta)->not->toBeFalse();
    expect($iRecvNew)->not->toBeFalse();
    expect($iRecvOld)->not->toBeFalse();
    expect((int) $iLate)->toBeLessThan((int) $iEarly);
    expect((int) $iEarly)->toBeLessThan((int) $iNoEta);
    expect((int) $iNoEta)->toBeLessThan((int) $iRecvNew);
    expect((int) $iRecvNew)->toBeLessThan((int) $iRecvOld);
});
