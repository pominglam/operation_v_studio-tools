<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Services\Maintenance\DatabaseBackupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

it('creates a backup zip containing external asset images', function (): void {
    $disk = Storage::disk('local');

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091111',
        'sku' => 'IMG-BACKUP-1',
        'barcode' => null,
        'description' => 'Backup images test',
        'handle' => null,
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'is_ready' => false,
    ]);

    $storagePath = 'hlj/images/IMG-BACKUP-1/test.jpg';
    $disk->put($storagePath, 'fake-image-bytes');

    ProductExternalAsset::query()->create([
        'product_id' => $product->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => $storagePath,
        'filename' => 'test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 15,
        'shopify_enabled' => true,
    ]);

    $svc = app(DatabaseBackupService::class);
    $result = $svc->backup();

    expect($result['filename'])->toEndWith('.zip');
    expect(File::exists($result['path']))->toBeTrue();

    $zip = new \ZipArchive();
    expect($zip->open($result['path']))->toBeTrue();
    try {
        expect($zip->locateName('storage/app/'.$storagePath))->not->toBeFalse();
        $hasDb = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (str_starts_with($n, 'db/') && (str_ends_with($n, '.sql') || str_ends_with($n, '.sqlite'))) {
                $hasDb = true;
                break;
            }
        }
        expect($hasDb)->toBeTrue();
    } finally {
        $zip->close();
        // cleanup best-effort
        File::delete($result['path']);
    }
});

it('still creates a backup even if some referenced images are missing on disk', function (): void {
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091112',
        'sku' => 'IMG-BACKUP-2',
        'barcode' => null,
        'description' => 'Backup images missing file test',
        'handle' => null,
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'is_ready' => false,
    ]);

    ProductExternalAsset::query()->create([
        'product_id' => $product->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => 'hlj/images/IMG-BACKUP-2/missing.jpg',
        'filename' => 'missing.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => null,
        'shopify_enabled' => false,
    ]);

    $svc = app(DatabaseBackupService::class);
    $result = $svc->backup();

    expect($result['filename'])->toEndWith('.zip');
    expect(File::exists($result['path']))->toBeTrue();

    File::delete($result['path']);
});

