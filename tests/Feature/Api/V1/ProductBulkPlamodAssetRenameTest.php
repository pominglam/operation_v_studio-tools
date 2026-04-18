<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
use Illuminate\Support\Facades\Storage;

it('queues a rename batch for selected products (even off/hidden sources)', function (): void {
    Storage::fake('local');
    config(['queue.default' => 'sync']);

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

    $plamodAsset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => $oldPath,
        'filename' => '[PLAMOD1] MG RX-78 GP03S Gundam.png',
        'mime_type' => 'image/png',
        'size_bytes' => 3,
        'checksum_sha256' => null,
    ]);

    // Simulate an "off/hidden" image from another source.
    $oldHljPath = 'hlj/images/5060001/Some HLJ Image 1.jpg';
    Storage::disk('local')->put($oldHljPath, 'img2');
    $hljAsset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => $oldHljPath,
        'filename' => 'Some HLJ Image 1.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 4,
        'checksum_sha256' => null,
        'shopify_enabled' => false,
    ]);

    $res = $this->postJson('/api/v1/products/bulk/plamod-assets/rename', [
        'ids' => [$p->uuid],
    ]);

    $res->assertStatus(202);
    $res->assertJsonPath('ok', true);
    $res->assertJsonPath('queued', 1);
    $res->assertJsonStructure(['batch_id']);

    $plamodAsset->refresh();
    $hljAsset->refresh();

    expect($plamodAsset->filename)->toBe("mg-rx-78-gp03s-gundam-01-{$plamodAsset->id}.png");
    expect($plamodAsset->storage_path)->toContain("mg-rx-78-gp03s-gundam-01-{$plamodAsset->id}.png");
    expect(Storage::disk('local')->exists($plamodAsset->storage_path))->toBeTrue();
    expect(Storage::disk('local')->exists($oldPath))->toBeFalse();

    // Second asset should also be renamed, even though shopify_enabled=false.
    expect($hljAsset->filename)->toBe("mg-rx-78-gp03s-gundam-02-{$hljAsset->id}.jpg");
    expect($hljAsset->storage_path)->toContain("mg-rx-78-gp03s-gundam-02-{$hljAsset->id}.jpg");
    expect(Storage::disk('local')->exists($hljAsset->storage_path))->toBeTrue();
    expect(Storage::disk('local')->exists($oldHljPath))->toBeFalse();
});

it('increments index when the target filename already exists', function (): void {
    Storage::fake('local');
    config(['queue.default' => 'sync']);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090002',
        'sku' => '5060002',
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

    $oldPath = 'plamod/extracted/5060002/20251225-000000/images/[PLAMOD1] MG RX-78 GP03S Gundam.png';
    Storage::disk('local')->put($oldPath, 'img');
    $plamodAsset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => $oldPath,
        'filename' => '[PLAMOD1] MG RX-78 GP03S Gundam.png',
        'mime_type' => 'image/png',
        'size_bytes' => 3,
        'checksum_sha256' => null,
    ]);

    $oldHljPath = 'hlj/images/5060002/Some HLJ Image 1.jpg';
    Storage::disk('local')->put($oldHljPath, 'img2');
    $hljAsset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => $oldHljPath,
        'filename' => 'Some HLJ Image 1.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 4,
        'checksum_sha256' => null,
        'shopify_enabled' => false,
    ]);

    // Create a collision file in the same directory as the first asset's target.
    $collisionPath = "plamod/extracted/5060002/20251225-000000/images/mg-rx-78-gp03s-gundam-01-{$plamodAsset->id}.png";
    Storage::disk('local')->put($collisionPath, 'already-here');

    $res = $this->postJson('/api/v1/products/bulk/plamod-assets/rename', [
        'ids' => [$p->uuid],
    ]);

    $res->assertStatus(202);
    $res->assertJsonPath('ok', true);
    $res->assertJsonPath('queued', 1);
    $res->assertJsonStructure(['batch_id']);

    $plamodAsset->refresh();
    $hljAsset->refresh();

    expect($plamodAsset->filename)->toBe("mg-rx-78-gp03s-gundam-02-{$plamodAsset->id}.png");
    expect($plamodAsset->storage_path)->toContain("mg-rx-78-gp03s-gundam-02-{$plamodAsset->id}.png");
    expect(Storage::disk('local')->exists($plamodAsset->storage_path))->toBeTrue();
    expect(Storage::disk('local')->exists($collisionPath))->toBeTrue();

    // Second asset should keep its seed index (02) since assetId keeps it unique.
    expect($hljAsset->filename)->toBe("mg-rx-78-gp03s-gundam-02-{$hljAsset->id}.jpg");
    expect($hljAsset->storage_path)->toContain("mg-rx-78-gp03s-gundam-02-{$hljAsset->id}.jpg");
    expect(Storage::disk('local')->exists($hljAsset->storage_path))->toBeTrue();
});

it('uses the product grid name (description) for the filename slug', function (): void {
    Storage::fake('local');
    config(['queue.default' => 'sync']);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090003',
        'sku' => '5060003',
        'barcode' => null,
        'description' => 'Grid Name MG Delta Plus',
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    // External content title differs, but should NOT affect SEO filenames.
    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'title' => 'Different HLJ Title That Should Not Be Used',
        'description_html' => '<p>HLJ</p>',
        'attributes_json' => null,
    ]);

    $oldPath = 'plamod/extracted/5060003/20251225-000000/images/any.png';
    Storage::disk('local')->put($oldPath, 'img');
    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => $oldPath,
        'filename' => 'any.png',
        'mime_type' => 'image/png',
        'size_bytes' => 3,
        'checksum_sha256' => null,
    ]);

    $res = $this->postJson('/api/v1/products/bulk/plamod-assets/rename', [
        'ids' => [$p->uuid],
    ]);

    $res->assertStatus(202);
    $res->assertJsonPath('ok', true);
    $res->assertJsonPath('queued', 1);
    $res->assertJsonStructure(['batch_id']);

    $asset->refresh();
    expect($asset->filename)->toBe("grid-name-mg-delta-plus-01-{$asset->id}.png");
});

it('ensures renamed files are readable by the shopify images server', function (): void {
    Storage::fake('local');
    config(['queue.default' => 'sync']);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090004',
        'sku' => '5060004',
        'barcode' => null,
        'description' => 'Readable Perms Product',
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    $oldPath = 'newtype/images/5060004/Original Name.png';
    Storage::disk('local')->put($oldPath, 'img');

    $disk = Storage::disk('local');
    $oldAbs = $disk->path($oldPath);
    $oldDirAbs = dirname($oldAbs);

    // Simulate restrictive perms created by another process/container.
    @chmod($oldDirAbs, 0700);
    @chmod($oldAbs, 0600);

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'newtype',
        'kind' => 'image',
        'storage_path' => $oldPath,
        'filename' => 'Original Name.png',
        'mime_type' => 'image/png',
        'size_bytes' => 3,
        'checksum_sha256' => null,
    ]);

    $res = $this->postJson('/api/v1/products/bulk/plamod-assets/rename', [
        'ids' => [$p->uuid],
    ]);

    $res->assertStatus(202);

    $asset->refresh();
    expect(Storage::disk('local')->exists($asset->storage_path))->toBeTrue();

    $abs = $disk->path($asset->storage_path);
    $dirAbs = dirname($abs);

    $filePerms = fileperms($abs);
    $dirPerms = fileperms($dirAbs);

    // World-readable bit (004) and world-executable bit (001) should be set.
    expect(($filePerms & 0x0004) !== 0)->toBeTrue();
    expect(($dirPerms & 0x0001) !== 0)->toBeTrue();
});
