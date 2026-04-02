<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('preflights missing barcodes and requires force to override available qty', function (): void {
    Storage::fake('local');

    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091001',
        'sku' => 'SKU-1',
        'barcode' => '111',
        'description' => 'P1',
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 9,
    ]);
    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091002',
        'sku' => 'SKU-2',
        'barcode' => '222',
        'description' => 'P2',
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 5,
    ]);
    $p3 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091003',
        'sku' => 'SKU-3',
        'barcode' => '333',
        'description' => 'P3',
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 2,
    ]);

    // Supports optional 2nd column qty: blank means 1.
    // 111 appears three times => 3. 222 appears once with qty 4 => 4.
    $csv = "111,\n111,\n111,\n222,4\n999,\n";
    $file = UploadedFile::fake()->createWithContent('scan.csv', $csv);

    $res = $this->postJson('/api/v1/products/import-inventory-qty-override', [
        'file' => $file,
    ]);

    $res->assertStatus(422);
    $res->assertJsonPath('blocked', true);
    $res->assertJsonPath('can_force', true);
    $res->assertJsonPath('missing_in_system.0', '999');

    // Nothing changed yet.
    $p1->refresh();
    $p2->refresh();
    $p3->refresh();
    expect($p1->available_qty)->toBe(9);
    expect($p2->available_qty)->toBe(5);
    expect($p3->available_qty)->toBe(2);

    // Force update proceeds.
    $res2 = $this->postJson('/api/v1/products/import-inventory-qty-override', [
        'file' => $file,
        'force' => true,
    ]);

    $res2->assertOk();
    $res2->assertJsonPath('lines_parsed', 5);
    $res2->assertJsonPath('unique_barcodes', 3);
    $res2->assertJsonPath('updated_products', 2);
    $res2->assertJsonPath('forced', true);
    $res2->assertJsonPath('missing_in_system.0', '999');

    $p1->refresh();
    $p2->refresh();
    $p3->refresh();

    expect($p1->available_qty)->toBe(3);
    expect($p2->available_qty)->toBe(4);
    expect($p3->available_qty)->toBe(0);
});

it('supports skip mode (products not present are left unchanged)', function (): void {
    Storage::fake('local');

    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091101',
        'sku' => 'SKU-SKIP-1',
        'barcode' => '111',
        'description' => 'P1',
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 9,
    ]);
    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091102',
        'sku' => 'SKU-SKIP-2',
        'barcode' => '222',
        'description' => 'P2',
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 5,
    ]);
    $p3 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000091103',
        'sku' => 'SKU-SKIP-3',
        'barcode' => '333',
        'description' => 'P3',
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 2,
    ]);

    $csv = "111,2\n222,4\n";
    $file = UploadedFile::fake()->createWithContent('scan.csv', $csv);

    $res = $this->postJson('/api/v1/products/import-inventory-qty-override', [
        'file' => $file,
        'missing_products_mode' => 'skip',
    ]);

    $res->assertOk();
    $res->assertJsonPath('updated_products', 2);
    $res->assertJsonPath('reset_products', 0);

    $p1->refresh();
    $p2->refresh();
    $p3->refresh();

    expect($p1->available_qty)->toBe(2);
    expect($p2->available_qty)->toBe(4);
    // Not present in file: should be untouched in skip mode.
    expect($p3->available_qty)->toBe(2);
});
