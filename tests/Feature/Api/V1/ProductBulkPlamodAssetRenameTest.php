<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use Illuminate\Support\Facades\Storage;

it('renames plamod image assets for selected products', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090001',
        'sku' => '5060001',
        'barcode' => null,
        'description' => 'MG RX-78 GP03S Gundam',
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    $oldPath = 'plamod/extracted/5060001/20251225-000000/images/[PLAMOD1] MG RX-78 GP03S Gundam.png';
    Storage::disk('local')->put($oldPath, 'img');

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => $oldPath,
        'filename' => '[PLAMOD1] MG RX-78 GP03S Gundam.png',
        'mime_type' => 'image/png',
        'size_bytes' => 3,
        'checksum_sha256' => null,
    ]);

    $res = $this->postJson('/api/v1/products/bulk/plamod-assets/rename', [
        'ids' => [$p->uuid],
    ]);

    $res->assertOk();
    $res->assertJsonPath('ok', true);
    $res->assertJsonPath('renamed_assets', 1);

    $asset->refresh();

    expect($asset->filename)->toBe("mg-rx-78-gp03s-gundam-01-{$asset->id}.png");
    expect($asset->storage_path)->toContain("mg-rx-78-gp03s-gundam-01-{$asset->id}.png");
    expect(Storage::disk('local')->exists($asset->storage_path))->toBeTrue();
    expect(Storage::disk('local')->exists($oldPath))->toBeFalse();
});






