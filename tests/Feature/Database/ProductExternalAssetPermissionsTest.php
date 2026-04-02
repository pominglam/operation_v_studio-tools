<?php

declare(strict_types=1);

use App\DAL\Products\ProductExternalAssetRepository;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

it('ensures newly written asset paths are world-readable (prevents shopify-images 404 due to 0700 dirs)', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000099001',
        'sku' => 'SKU-PERMS-1',
        'barcode' => null,
        'description' => 'Perms Test',
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

    $disk = Storage::disk('local');
    $storagePath = 'newtype/images/_perms_test/pokemon-model-kit-mega-rayquaza-03-999001.png';
    $abs = $disk->path($storagePath);
    $dirAbs = dirname($abs);

    @mkdir($dirAbs, 0700, true);
    file_put_contents($abs, 'img');
    @chmod($abs, 0600);

    expect(is_dir($dirAbs))->toBeTrue();
    expect(is_file($abs))->toBeTrue();

    /** @var ProductExternalAssetRepository $repo */
    $repo = app(ProductExternalAssetRepository::class);
    $repo->replaceForProduct((int) $p->id, 'newtype', [
        [
            'kind' => 'image',
            'storage_path' => $storagePath,
            'filename' => basename($storagePath),
            'mime_type' => 'image/png',
            'size_bytes' => 3,
        ],
    ]);

    $filePerms = fileperms($abs);
    $dirPerms = fileperms($dirAbs);

    // World-readable bit (004) and world-executable bit (001) should be set.
    expect(($filePerms & 0x0004) !== 0)->toBeTrue();
    expect(($dirPerms & 0x0001) !== 0)->toBeTrue();

    // Cleanup (best-effort).
    @unlink($abs);
    @rmdir($dirAbs);
});
