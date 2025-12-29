<?php

declare(strict_types=1);

use App\Jobs\SyncPlamodAssetsJob;
use App\Models\Product;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

it('queues a Plamod sync job for a product', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('plamod/.db_backup_created.json', '{"created_at":"2025-12-24T00:00:00Z"}');

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050001',
        'sku' => '5063838',
        'description' => 'Plamod product',
        'vendor' => 'Plamod',
    ]);

    Bus::fake();

    $res = $this->postJson('/api/v1/products/00000000-0000-0000-0000-000000050001/plamod/sync');

    $res->assertStatus(202);
    $res->assertJsonPath('ok', true);
    $res->assertJsonStructure(['sync_uuid']);

    Bus::assertDispatched(SyncPlamodAssetsJob::class, function (SyncPlamodAssetsJob $job): bool {
        return $job->productUuid === '00000000-0000-0000-0000-000000050001'
            && $job->attemptPlamodAssets === false;
    });
});

it('queues a product info sync job (force Plamod assets attempt) for a product', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('plamod/.db_backup_created.json', '{"created_at":"2025-12-24T00:00:00Z"}');

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050002',
        'sku' => '5058815',
        'description' => 'Any product',
        'vendor' => 'MSMN',
    ]);

    Bus::fake();

    $res = $this->postJson('/api/v1/products/00000000-0000-0000-0000-000000050002/product-info/sync');

    $res->assertStatus(202);
    $res->assertJsonPath('ok', true);
    $res->assertJsonStructure(['sync_uuid']);

    Bus::assertDispatched(SyncPlamodAssetsJob::class, function (SyncPlamodAssetsJob $job): bool {
        return $job->productUuid === '00000000-0000-0000-0000-000000050002'
            && $job->attemptPlamodAssets === true;
    });
});


