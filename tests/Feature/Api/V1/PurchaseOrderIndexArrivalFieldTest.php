<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;

it('includes received_date in purchase order index payload', function (): void {
    $notArrived = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'ordered_date' => '2026-01-01',
        'estimated_arrival_date' => '2026-01-09',
        'received_date' => null,
    ]);

    $arrived = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
        'ordered_date' => '2026-01-02',
        'estimated_arrival_date' => '2026-01-06',
        'received_date' => '2026-01-05',
    ]);

    $res = $this->getJson('/api/v1/purchase-orders?per_page=200&sort_dir=desc');
    $res->assertOk();

    $rows = collect($res->json('data') ?? []);

    $arrivedRow = $rows->firstWhere('id', (string) $arrived->uuid);
    $notArrivedRow = $rows->firstWhere('id', (string) $notArrived->uuid);

    expect($arrivedRow)->toBeArray();
    expect($notArrivedRow)->toBeArray();
    expect($arrivedRow['received_date'] ?? null)->toBe('2026-01-05');
    expect($arrivedRow['estimated_arrival_date'] ?? null)->toBe('2026-01-06');
    expect(array_key_exists('received_date', $notArrivedRow))->toBeTrue();
    expect(array_key_exists('estimated_arrival_date', $notArrivedRow))->toBeTrue();
    expect($notArrivedRow['estimated_arrival_date'])->toBe('2026-01-09');
    expect($notArrivedRow['received_date'])->toBeNull();
});

