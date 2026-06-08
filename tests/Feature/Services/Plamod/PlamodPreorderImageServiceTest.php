<?php

declare(strict_types=1);

use App\Models\PlamodPreorder;
use App\Models\Product;
use App\Services\Plamod\PlamodPreorderImageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('deletes images for dropped unlinked skus older than 15 days on cleanup', function (): void {
    $path = 'plamod/preorder-images/stale-sku.jpg';
    Storage::disk('local')->put($path, 'fake-image');

    PlamodPreorder::query()->create([
        'sku' => 'STALE-SKU',
        'product_name' => 'Stale',
        'image_storage_path' => $path,
        'image_download_status' => PlamodPreorder::IMAGE_STATUS_COMPLETED,
        'dropped_at' => now()->subDays(16),
    ]);

    PlamodPreorder::query()->create([
        'sku' => 'LINKED-SKU',
        'product_name' => 'Linked',
        'image_storage_path' => 'plamod/preorder-images/linked.jpg',
        'image_download_status' => PlamodPreorder::IMAGE_STATUS_COMPLETED,
        'dropped_at' => now()->subDays(20),
    ]);
    Storage::disk('local')->put('plamod/preorder-images/linked.jpg', 'linked');

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000099901',
        'sku' => 'LINKED-SKU',
        'description' => 'In catalog',
        'vendor' => 'Plamod',
    ]);

    $deleted = app(PlamodPreorderImageService::class)->cleanupStaleUnlinkedImages();

    expect($deleted)->toBe(1);
    expect(Storage::disk('local')->exists($path))->toBeFalse();
    expect(Storage::disk('local')->exists('plamod/preorder-images/linked.jpg'))->toBeTrue();

    $stale = PlamodPreorder::query()->where('sku', 'STALE-SKU')->firstOrFail();
    expect($stale->image_storage_path)->toBeNull();
    expect($stale->image_download_status)->toBe(PlamodPreorder::IMAGE_STATUS_PENDING);
});

it('downloads image for active preorder sku', function (): void {
    Http::fake([
        'https://example.com/kit.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    PlamodPreorder::query()->create([
        'sku' => 'IMG001',
        'product_name' => 'Image test',
        'source_image_url' => 'https://example.com/kit.png',
        'image_download_status' => PlamodPreorder::IMAGE_STATUS_PENDING,
    ]);

    $ok = app(PlamodPreorderImageService::class)->downloadForSku('IMG001');
    expect($ok)->toBeTrue();

    $row = PlamodPreorder::query()->where('sku', 'IMG001')->firstOrFail();
    expect($row->image_storage_path)->not->toBeNull();
    expect($row->image_download_status)->toBe(PlamodPreorder::IMAGE_STATUS_COMPLETED);
    expect(Storage::disk('local')->exists((string) $row->image_storage_path))->toBeTrue();
});
