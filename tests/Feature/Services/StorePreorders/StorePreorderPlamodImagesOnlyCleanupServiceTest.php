<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\StorePreorder;
use App\Services\StorePreorders\StorePreorderPlamodImagesOnlyCleanupService;
use Illuminate\Support\Facades\Bus;

it('removes HLJ photos from store-preorder products and keeps Plamod and manual uploads', function (): void {
    Bus::fake();

    $plamod = Product::query()->create([
        'sku' => 'GSC-PO-IMG',
        'description' => 'MODEROID test',
        'vendor' => 'Plamod',
    ]);
    StorePreorder::query()->create([
        'product_id' => $plamod->id,
        'plamod_sku' => 'GSC-PO-IMG',
        'status' => 'open',
        'deposit_percent' => '20.00',
        'opened_at' => now(),
    ]);
    ProductExternalAsset::query()->create([
        'product_id' => $plamod->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/keep.png',
        'filename' => 'keep.png',
        'shopify_enabled' => true,
    ]);
    ProductExternalAsset::query()->create([
        'product_id' => $plamod->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => 'hlj/drop.jpg',
        'filename' => 'drop.jpg',
        'shopify_enabled' => true,
    ]);

    $manual = Product::query()->create([
        'sku' => 'OVS-manual-img',
        'description' => 'Manual offer',
        'vendor' => null,
    ]);
    StorePreorder::query()->create([
        'product_id' => $manual->id,
        'plamod_sku' => 'OVS-manual-img',
        'status' => 'open',
        'deposit_percent' => '20.00',
        'opened_at' => now(),
    ]);
    ProductExternalAsset::query()->create([
        'product_id' => $manual->id,
        'source' => 'manual_upload',
        'kind' => 'image',
        'storage_path' => 'manual/keep.jpg',
        'filename' => 'keep.jpg',
        'shopify_enabled' => true,
    ]);

    $result = app(StorePreorderPlamodImagesOnlyCleanupService::class)->cleanupAndQueue();

    expect($result->productsCleaned)->toBe(1);
    expect($result->assetsRemoved)->toBe(1);
    expect($result->imagePushQueued)->toBe(1);
    expect(ProductExternalAsset::query()->where('product_id', $plamod->id)->where('source', 'hlj')->count())->toBe(0);
    expect(ProductExternalAsset::query()->where('product_id', $plamod->id)->where('source', 'plamod')->count())->toBe(1);
    expect(ProductExternalAsset::query()->where('product_id', $manual->id)->where('source', 'manual_upload')->count())->toBe(1);
});
