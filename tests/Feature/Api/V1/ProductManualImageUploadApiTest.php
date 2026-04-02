<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('uploads multiple manual images for a product and exposes them in product-info', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000077777',
        'sku' => 'MANUAL-UPLOAD-1',
        'description' => 'Manual upload product',
        'vendor' => 'Plamod',
    ]);

    // Existing image with explicit order to ensure new uploads append.
    ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'bandai',
        'kind' => 'image',
        'storage_path' => 'bandai/images/x.jpg',
        'filename' => 'x.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 123,
        'checksum_sha256' => null,
        'sort_order' => 1,
        'shopify_enabled' => true,
    ]);

    // Use real PNG bytes (GD isn't available in the test container).
    $png1x1 = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMB/6VnVJ8AAAAASUVORK5CYII=',
        true,
    );
    expect($png1x1)->not->toBeFalse();
    /** @var string $png1x1 */
    $f1 = UploadedFile::fake()->createWithContent('a.png', $png1x1);
    $f2 = UploadedFile::fake()->createWithContent('b.png', $png1x1);

    $res = $this->post("/api/v1/products/{$p->uuid}/assets/manual-upload", [
        'files' => [$f1, $f2],
    ]);

    $res->assertStatus(201);
    $res->assertJsonPath('ok', true);
    $res->assertJsonPath('data.created', 2);

    $info = $this->getJson("/api/v1/products/{$p->uuid}/product-info");
    $info->assertOk();

    $assets = $info->json('data.assets');
    expect($assets)->toBeArray();

    $manual = array_values(array_filter($assets, static fn (array $a): bool => ($a['source'] ?? null) === 'manual_upload'));
    expect($manual)->toHaveCount(2);

    // Both should be images and have view URLs.
    expect($manual[0]['kind'])->toBe('image');
    expect((string) $manual[0]['view_url'])->toContain('/api/v1/product-assets/');
});

it('rejects non-image uploads for manual images', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000077778',
        'sku' => 'MANUAL-UPLOAD-2',
        'description' => 'Manual upload product',
        'vendor' => 'Plamod',
    ]);

    $bad = UploadedFile::fake()->create('not-an-image.txt', 1, 'text/plain');

    $this->post("/api/v1/products/{$p->uuid}/assets/manual-upload", [
        'files' => [$bad],
    ])->assertStatus(422);
});
