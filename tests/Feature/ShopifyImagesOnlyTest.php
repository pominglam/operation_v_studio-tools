<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Services\Shopify\ShopifyImageUrlSigner;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;

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


