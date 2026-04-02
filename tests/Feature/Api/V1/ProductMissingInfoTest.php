<?php

declare(strict_types=1);

use App\Jobs\SyncPlamodAssetsJob;
use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
use App\Models\ProductSellingPrice;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

it('filters products by missing flags', function (): void {
    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000060001',
        'sku' => 'MISS-1',
        'barcode' => null,
        'description' => 'Missing everything',
        'handle' => null,
    ]);

    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000060002',
        'sku' => 'HAVE-1',
        'barcode' => '123',
        'description' => 'Has everything',
        'handle' => 'have-1',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p2->id,
        'product_uuid' => $p2->uuid,
        'selling_price' => '10.00',
        'currency' => 'CAD',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p2->id,
        'source' => 'hlj',
        'title' => 'HLJ',
        'description_html' => '<p>desc</p>',
        'attributes_json' => [],
    ]);

    // A product that only has a description from a non-HLJ/Plamod source should still count as "has description".
    $p3 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000060003',
        'sku' => 'HAVE-OTHER-DESC-1',
        'barcode' => '999',
        'description' => 'Has external desc (other source)',
        'handle' => 'have-other-desc-1',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p3->id,
        'product_uuid' => $p3->uuid,
        'selling_price' => '10.00',
        'currency' => 'CAD',
    ]);
    ProductExternalContent::query()->create([
        'product_id' => $p3->id,
        'source' => 'meeplemart',
        'title' => 'Meeplemart',
        'description_html' => '<p>desc</p>',
        'attributes_json' => [],
    ]);
    ProductExternalAsset::query()->create([
        'product_id' => $p3->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/z.png',
        'filename' => 'z.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1,
        'checksum_sha256' => null,
    ]);

    ProductExternalAsset::query()->create([
        'product_id' => $p2->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/x.png',
        'filename' => 'x.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1,
        'checksum_sha256' => null,
    ]);

    // Images from non-plamod sources should still count as PDP images.
    $p4 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000060004',
        'sku' => 'HAVE-OTHER-IMAGE-1',
        'barcode' => '998',
        'description' => 'Has image from other source',
        'handle' => 'have-other-image-1',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p4->id,
        'product_uuid' => $p4->uuid,
        'selling_price' => '10.00',
        'currency' => 'CAD',
    ]);
    ProductExternalContent::query()->create([
        'product_id' => $p4->id,
        'source' => 'hlj',
        'title' => 'HLJ',
        'description_html' => '<p>desc</p>',
        'attributes_json' => [],
    ]);
    ProductExternalAsset::query()->create([
        'product_id' => $p4->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => 'hlj/extracted/a.png',
        'filename' => 'a.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1,
        'checksum_sha256' => null,
    ]);

    $this->getJson('/api/v1/products?per_page=100&missing[]=barcode')
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'MISS-1')
        ->assertJsonMissing(['sku' => 'HAVE-1']);

    $this->getJson('/api/v1/products?per_page=100&missing[]=handle')
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'MISS-1')
        ->assertJsonMissing(['sku' => 'HAVE-1']);

    $this->getJson('/api/v1/products?per_page=100&missing[]=selling_price')
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'MISS-1')
        ->assertJsonMissing(['sku' => 'HAVE-1']);

    $this->getJson('/api/v1/products?per_page=100&missing[]=pdp_description')
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'MISS-1')
        ->assertJsonMissing(['sku' => 'HAVE-1'])
        ->assertJsonMissing(['sku' => 'HAVE-OTHER-DESC-1']);

    $this->getJson('/api/v1/products?per_page=100&missing[]=pdp_images')
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'MISS-1')
        ->assertJsonMissing(['sku' => 'HAVE-1'])
        ->assertJsonMissing(['sku' => 'HAVE-OTHER-IMAGE-1']);

    $this->getJson('/api/v1/products?per_page=100&missing[]=ok')
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'HAVE-1')
        ->assertJsonMissing(['sku' => 'MISS-1']);
});

it('queues sync jobs for products missing PDP info', function (): void {
    $missing = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000060101',
        'sku' => 'SYNC-MISS-1',
        'description' => 'Missing PDP',
    ]);

    $ok = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000060102',
        'sku' => 'SYNC-OK-1',
        'description' => 'Has PDP',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $ok->id,
        'source' => 'hlj',
        'title' => 'HLJ',
        'description_html' => '<p>desc</p>',
        'attributes_json' => [],
    ]);

    ProductExternalAsset::query()->create([
        'product_id' => $ok->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/y.png',
        'filename' => 'y.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1,
        'checksum_sha256' => null,
    ]);

    Queue::fake();

    $this->postJson('/api/v1/products/sync-missing-info', [
        'missing' => ['pdp_description', 'pdp_images'],
        'dry_run' => true,
    ])
        ->assertOk()
        ->assertJsonPath('queued', 1)
        ->assertJsonPath('dry_run', true)
        ->assertJsonPath('batch_id', null);

    $this->postJson('/api/v1/products/sync-missing-info', [
        'missing' => ['pdp_description', 'pdp_images'],
        'dry_run' => false,
    ])
        ->assertOk()
        ->assertJsonPath('queued', 1)
        ->assertJsonPath('dry_run', false)
        ->assertJsonStructure(['batch_id']);

    Queue::assertPushed(SyncPlamodAssetsJob::class, function (SyncPlamodAssetsJob $job) use ($missing): bool {
        return $job->productUuid === $missing->uuid
            && $job->syncUuid !== ''
            && $job->queue === 'pdp_sync'
            && $job->attemptPlamodAssets === true;
    });

    Queue::assertNotPushed(SyncPlamodAssetsJob::class, function (SyncPlamodAssetsJob $job) use ($ok): bool {
        return $job->productUuid === $ok->uuid;
    });
});

it('runs missing PDP info sync as a batch that allows failures', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000060201',
        'sku' => 'SYNC-MISS-2',
        'description' => 'Missing PDP',
    ]);

    Bus::fake();

    $this->postJson('/api/v1/products/sync-missing-info', [
        'missing' => ['pdp_description', 'pdp_images'],
        'dry_run' => false,
    ])
        ->assertOk()
        ->assertJsonPath('queued', 1)
        ->assertJsonPath('dry_run', false)
        ->assertJsonStructure(['batch_id']);

    Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch): bool {
        return $batch->name === 'sync_missing_pdp_info'
            && (($batch->options['allowFailures'] ?? false) === true);
    });
});


