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

it('deletes a manually uploaded product image and its stored file', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000077779',
        'sku' => 'MANUAL-UPLOAD-DELETE',
        'description' => 'Manual upload delete product',
        'vendor' => 'Plamod',
    ]);

    Storage::disk('local')->put('manual_upload/images/delete-me.png', 'fake image bytes');

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'manual_upload',
        'kind' => 'image',
        'storage_path' => 'manual_upload/images/delete-me.png',
        'filename' => 'delete-me.png',
        'mime_type' => 'image/png',
        'size_bytes' => 16,
        'checksum_sha256' => null,
        'sort_order' => 1,
        'shopify_enabled' => true,
    ]);

    $this->deleteJson("/api/v1/product-assets/{$asset->id}")
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(ProductExternalAsset::query()->whereKey($asset->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing('manual_upload/images/delete-me.png');
});

it('does not delete non-manual product images through the manual delete endpoint', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000077780',
        'sku' => 'MANUAL-UPLOAD-DENIED',
        'description' => 'Manual upload denied product',
        'vendor' => 'Plamod',
    ]);

    Storage::disk('local')->put('bandai/images/keep-me.png', 'fake image bytes');

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'bandai',
        'kind' => 'image',
        'storage_path' => 'bandai/images/keep-me.png',
        'filename' => 'keep-me.png',
        'mime_type' => 'image/png',
        'size_bytes' => 16,
        'checksum_sha256' => null,
        'sort_order' => 1,
        'shopify_enabled' => true,
    ]);

    $this->deleteJson("/api/v1/product-assets/{$asset->id}")
        ->assertStatus(403)
        ->assertJsonPath('error', 'manual_upload_only');

    expect(ProductExternalAsset::query()->whereKey($asset->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists('bandai/images/keep-me.png');
});
