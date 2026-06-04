<?php

declare(strict_types=1);

use App\Models\Shopify\ShopifySyncLog;
use App\Services\Shopify\Admin\Sync\ShopifyCatalogMirrorFreshnessService;

it('treats catalog mirror as fresh when products and inventory_levels completed within max age', function (): void {
    $finished = now()->subMinutes(30);

    ShopifySyncLog::query()->create([
        'sync_key' => 'products',
        'status' => 'completed',
        'started_at' => $finished->copy()->subMinute(),
        'finished_at' => $finished,
    ]);
    ShopifySyncLog::query()->create([
        'sync_key' => 'inventory_levels',
        'status' => 'completed',
        'started_at' => $finished->copy()->subMinute(),
        'finished_at' => $finished,
    ]);

    $service = app(ShopifyCatalogMirrorFreshnessService::class);

    expect($service->isFresh())->toBeTrue();
    expect($service->snapshot()['mirror_fresh'])->toBeTrue();
});

it('treats catalog mirror as stale when last products sync is too old', function (): void {
    ShopifySyncLog::query()->create([
        'sync_key' => 'products',
        'status' => 'completed',
        'started_at' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
    ]);
    ShopifySyncLog::query()->create([
        'sync_key' => 'inventory_levels',
        'status' => 'completed',
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(10),
    ]);

    expect(app(ShopifyCatalogMirrorFreshnessService::class)->isFresh())->toBeFalse();
});
