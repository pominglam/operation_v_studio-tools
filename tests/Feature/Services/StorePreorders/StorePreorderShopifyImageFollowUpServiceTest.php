<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\StorePreorder;
use App\Services\Products\PlamodPlaceholderImageDetector;
use App\Services\StorePreorders\StorePreorderShopifyImageFollowUpService;
use Illuminate\Support\Facades\Bus;

it('treats the Plamod No image graphic as missing so closed offers are recrawled', function (): void {
    Bus::fake();

    $product = Product::query()->create([
        'sku' => '5084730-TEST',
        'description' => 'ENTRY GRADE ECOPLA placeholder',
        'vendor' => 'Plamod',
    ]);
    StorePreorder::query()->create([
        'product_id' => $product->id,
        'plamod_sku' => '5084730-TEST',
        'status' => 'closed',
        'deposit_percent' => '20.00',
        'opened_at' => now()->subDays(30),
        'closed_at' => now()->subDay(),
    ]);
    ProductExternalAsset::query()->create([
        'product_id' => $product->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/placeholder.png',
        'filename' => 'placeholder.png',
        'checksum_sha256' => PlamodPlaceholderImageDetector::BANNED_SHA256[0],
        'shopify_enabled' => true,
    ]);

    $images = app(StorePreorderShopifyImageFollowUpService::class);
    expect($images->hasShopifyImages((string) $product->uuid))->toBeFalse();

    $result = $images->queueMissingPhotoCrawls();

    expect($result->missing)->toBe(1);
    expect($result->attached)->toBe(0);
    expect($result->imagePushQueued)->toBe(1);
    expect($result->queued)->toBe(1);
    expect(ProductExternalAsset::query()->where('product_id', $product->id)->where('kind', 'image')->count())->toBe(0);
});
