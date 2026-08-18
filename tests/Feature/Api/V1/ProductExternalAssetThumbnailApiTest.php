<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use Illuminate\Support\Facades\Storage;

function productExternalAssetTestPngBytes(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMB/6VnVJ8AAAAASUVORK5CYII=',
        true,
    );
    expect($png)->not->toBeFalse();

    /** @var string $png */
    return $png;
}

it('includes thumb_url for image assets in product-info', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070010',
        'sku' => 'THUMB-URL-1',
        'description' => 'Thumb url product',
        'vendor' => 'Plamod',
    ]);

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/images/thumb-url.jpg',
        'filename' => 'thumb-url.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 123,
        'checksum_sha256' => null,
        'sort_order' => 1,
    ]);

    $res = $this->getJson('/api/v1/products/00000000-0000-0000-0000-000000070010/product-info');
    $res->assertOk();
    $res->assertJsonPath('data.assets.0.thumb_url', '/api/v1/product-assets/'.$asset->id.'/thumb');
});

it('serves a thumbnail for an existing image asset', function (): void {
    Storage::fake('local');

    if (! extension_loaded('gd')) {
        $png = productExternalAssetTestPngBytes();
        Storage::disk('local')->put('manual_upload/images/thumb-serve.png', $png);
        $storagePath = 'manual_upload/images/thumb-serve.png';
        $filename = 'thumb-serve.png';
        $mimeType = 'image/png';
        $sizeBytes = strlen($png);
    } else {
        $jpeg = (function (): string {
            $canvas = imagecreatetruecolor(640, 480);
            expect($canvas)->not->toBeFalse();
            $blue = imagecolorallocate($canvas, 30, 64, 175);
            imagefilledrectangle($canvas, 0, 0, 640, 480, $blue);
            ob_start();
            imagejpeg($canvas, null, 90);
            /** @var string $bytes */
            $bytes = ob_get_clean();
            imagedestroy($canvas);

            return $bytes;
        })();

        $storagePath = 'manual_upload/images/thumb-serve.jpg';
        $filename = 'thumb-serve.jpg';
        $mimeType = 'image/jpeg';
        $sizeBytes = strlen($jpeg);
        Storage::disk('local')->put($storagePath, $jpeg);
    }

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070011',
        'sku' => 'THUMB-SERVE-1',
        'description' => 'Thumb serve product',
        'vendor' => 'Plamod',
    ]);

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'manual_upload',
        'kind' => 'image',
        'storage_path' => $storagePath,
        'filename' => $filename,
        'mime_type' => $mimeType,
        'size_bytes' => $sizeBytes,
        'checksum_sha256' => null,
        'sort_order' => 1,
    ]);

    if (extension_loaded('gd')) {
        $servePath = app(\App\Services\Products\ProductExternalAssetThumbnailService::class)
            ->resolveServePath($asset);
        expect($servePath?->mimeType)->toBe('image/jpeg');
        Storage::disk('local')->assertExists('product-external-assets/thumbs/'.$asset->id.'.jpg');
    }

    $res = $this->get("/api/v1/product-assets/{$asset->id}/thumb");
    $res->assertOk();

    if (extension_loaded('gd')) {
        $res->assertHeader('Content-Type', 'image/jpeg');
    } else {
        $res->assertHeader('Content-Type', 'image/png');
    }
});

it('returns 404 when thumbnail asset is missing on disk', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070012',
        'sku' => 'THUMB-MISSING-1',
        'description' => 'Missing file product',
        'vendor' => 'Plamod',
    ]);

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/images/missing.jpg',
        'filename' => 'missing.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 123,
        'checksum_sha256' => null,
        'sort_order' => 1,
    ]);

    $this->get("/api/v1/product-assets/{$asset->id}/thumb")->assertNotFound();
});

it('returns 404 when requesting a thumbnail for a non-image asset kind', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('plamod/files/manual.pdf', 'pdf');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070013',
        'sku' => 'THUMB-PDF-1',
        'description' => 'Pdf asset product',
        'vendor' => 'Plamod',
    ]);

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'file',
        'storage_path' => 'plamod/files/manual.pdf',
        'filename' => 'manual.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 3,
        'checksum_sha256' => null,
        'sort_order' => 1,
    ]);

    $this->get("/api/v1/product-assets/{$asset->id}/thumb")->assertNotFound();
});

it('deletes cached thumbnail when a manual upload image is deleted', function (): void {
    Storage::fake('local');

    if (extension_loaded('gd')) {
        $jpeg = (function (): string {
            $canvas = imagecreatetruecolor(320, 240);
            expect($canvas)->not->toBeFalse();
            $red = imagecolorallocate($canvas, 200, 20, 20);
            imagefilledrectangle($canvas, 0, 0, 320, 240, $red);
            ob_start();
            imagejpeg($canvas, null, 90);
            /** @var string $bytes */
            $bytes = ob_get_clean();
            imagedestroy($canvas);

            return $bytes;
        })();
        $storagePath = 'manual_upload/images/delete-thumb.jpg';
        $filename = 'delete-thumb.jpg';
        $mimeType = 'image/jpeg';
        $sizeBytes = strlen($jpeg);
        Storage::disk('local')->put($storagePath, $jpeg);
    } else {
        $png = productExternalAssetTestPngBytes();
        $storagePath = 'manual_upload/images/delete-thumb.png';
        $filename = 'delete-thumb.png';
        $mimeType = 'image/png';
        $sizeBytes = strlen($png);
        Storage::disk('local')->put($storagePath, $png);
    }

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070014',
        'sku' => 'THUMB-DELETE-1',
        'description' => 'Delete thumb product',
        'vendor' => 'Plamod',
    ]);

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'manual_upload',
        'kind' => 'image',
        'storage_path' => $storagePath,
        'filename' => $filename,
        'mime_type' => $mimeType,
        'size_bytes' => $sizeBytes,
        'checksum_sha256' => null,
        'sort_order' => 1,
    ]);

    if (extension_loaded('gd')) {
        $this->get("/api/v1/product-assets/{$asset->id}/thumb")->assertOk();
        Storage::disk('local')->assertExists('product-external-assets/thumbs/'.$asset->id.'.jpg');
    }

    $this->deleteJson("/api/v1/product-assets/{$asset->id}")
        ->assertOk()
        ->assertJsonPath('ok', true);

    Storage::disk('local')->assertMissing('product-external-assets/thumbs/'.$asset->id.'.jpg');
});
