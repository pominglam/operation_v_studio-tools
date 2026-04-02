<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Services\Shopify\ShopifyImageUrlSigner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

it('blocks non shopify-images paths when SHOPIFY_IMAGES_ONLY is true', function (): void {
    config()->set('app.shopify_images_only', true);

    $this->get('/')->assertNotFound();
    $this->get('/products')->assertNotFound();
});

it('serves signed shopify image URLs (and 404s without signature)', function (): void {
    Storage::fake('local');
    $signer = app(ShopifyImageUrlSigner::class);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090020',
        'sku' => 'SKU-IMG-1',
        'barcode' => null,
        'description' => 'Test',
        'handle' => null,
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    $path = 'plamod/extracted/SKU-IMG-1/20251226-000000/images/test.png';
    Storage::disk('local')->put($path, 'img');

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => $path,
        'filename' => 'test.png',
        'mime_type' => 'image/png',
        'size_bytes' => 3,
        'checksum_sha256' => null,
    ]);

    $this->get("/shopify-images/{$asset->id}")->assertNotFound();

    $signed = URL::temporarySignedRoute('shopify-images', now()->addMinutes(10), ['id' => $asset->id]);
    $res = $this->get($signed);
    $res->assertOk();
    $res->assertHeader('Content-Type', 'image/png');

    $pathSigned = $signer->sign($asset->id, now()->addMinutes(10)->getTimestamp());
    $res2 = $this->get("/shopify-images/{$asset->id}/{$pathSigned['expires']}/{$pathSigned['signature']}");
    $res2->assertOk();
    $res2->assertHeader('Content-Type', 'image/png');

    $res3 = $this->get("/shopify-images/{$asset->id}/{$pathSigned['expires']}/{$pathSigned['signature']}/test.png");
    $res3->assertOk();
    $res3->assertHeader('Content-Type', 'image/png');
});

it('repairs missing storage_path on demand by checksum (serves image)', function (): void {
    Storage::fake('local');
    $signer = app(ShopifyImageUrlSigner::class);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090021',
        'sku' => 'SKU-IMG-2',
        'barcode' => null,
        'description' => 'Test',
        'handle' => null,
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    // Original file exists under the expected directory, but with a non-SEO filename.
    $dir = 'plamod/extracted/SKU-IMG-2/20251226-000000/images';
    $oldPath = "{$dir}/Original Name (1).png";
    Storage::disk('local')->put($oldPath, 'img2');

    $expectedSha = hash('sha256', 'img2');

    // Simulate a broken rename: DB points to a new SEO path that doesn't exist.
    $brokenPath = "{$dir}/seo-name-01-999.png";
    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => $brokenPath,
        'filename' => 'seo-name-01-999.png',
        'mime_type' => 'image/png',
        'size_bytes' => 4,
        'checksum_sha256' => $expectedSha,
    ]);

    $signed = $signer->sign($asset->id, now()->addMinutes(10)->getTimestamp());
    $res = $this->get("/shopify-images/{$asset->id}/{$signed['expires']}/{$signed['signature']}/seo-name-01-999.png");
    $res->assertOk();
    $res->assertHeader('Content-Type', 'image/png');

    $asset->refresh();
    expect($asset->storage_path)->toBe($oldPath);
});

it('returns 404 (not 500) when disk cannot list contents (e.g. permission denied)', function (): void {
    $signer = app(ShopifyImageUrlSigner::class);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090022',
        'sku' => 'SKU-IMG-3',
        'barcode' => null,
        'description' => 'Test',
        'handle' => null,
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'newtype',
        'kind' => 'image',
        // Missing file, but checksum set triggers "repair by checksum" path.
        'storage_path' => 'private/newtype/images/5069371/seo-name-01.png',
        'filename' => 'seo-name-01.png',
        'mime_type' => 'image/png',
        'size_bytes' => 4,
        'checksum_sha256' => hash('sha256', 'x'),
    ]);

    $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andReturn(false);
    $disk->shouldReceive('files')->andThrow(new \RuntimeException('permission denied'));

    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    $signed = $signer->sign($asset->id, now()->addMinutes(10)->getTimestamp());
    $res = $this->get("/shopify-images/{$asset->id}/{$signed['expires']}/{$signed['signature']}/seo-name-01.png");
    $res->assertNotFound();
});