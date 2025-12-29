<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('imports shopify handles by Variant SKU and does not overwrite by default', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090010',
        'sku' => 'SKU-1',
        'barcode' => null,
        'description' => 'Test',
        'handle' => 'existing-handle',
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    $csv = implode("\n", [
        'Handle,Variant SKU',
        'new-handle,SKU-1',
    ]);

    $file = UploadedFile::fake()->createWithContent('shopify.csv', $csv);

    $res = $this->postJson('/api/v1/products/import-handles', [
        'file' => $file,
    ]);

    $res->assertOk();
    $res->assertJsonPath('would_update', 1);
    $res->assertJsonPath('updated', 1);

    $p->refresh();
    expect($p->handle)->toBe('new-handle');
});

it('overwrites handles', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090011',
        'sku' => 'SKU-2',
        'barcode' => null,
        'description' => 'Test',
        'handle' => 'old',
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    $csv = implode("\n", [
        'Handle,Variant SKU',
        'new-handle-2,SKU-2',
    ]);

    $file = UploadedFile::fake()->createWithContent('shopify.csv', $csv);

    $res = $this->postJson('/api/v1/products/import-handles', [
        'file' => $file,
    ]);

    $res->assertOk();
    $res->assertJsonPath('updated', 1);

    $p->refresh();
    expect($p->handle)->toBe('new-handle-2');
});

it('validates required columns for handle import', function (): void {
    $csv = implode("\n", [
        'Variant SKU',
        'SKU-1',
    ]);
    $file = UploadedFile::fake()->createWithContent('shopify.csv', $csv);

    $res = $this->postJson('/api/v1/products/import-handles', [
        'file' => $file,
    ]);

    $res->assertStatus(422);
    $res->assertJsonPath('message', 'Missing required column: Handle');
});


