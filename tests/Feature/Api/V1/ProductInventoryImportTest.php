<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Http\UploadedFile;

it('imports available inventory qty from Shopify CSV and returns not-updated products', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000040001',
        'sku' => 'MS-D20',
        'description' => 'Test product A',
        'available_qty' => 1,
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000040002',
        'sku' => 'MS-D15',
        'description' => 'Test product B',
        'available_qty' => 2,
    ]);

    $csv = implode("\n", [
        'Handle,Variant SKU,Variant Inventory Qty,Variant Price',
        'x,MS-D20,5,6.99',
        'x,SKU-NOT-IN-DB,3,1.00',
    ]);
    $file = UploadedFile::fake()->createWithContent('shopify.csv', $csv, 'text/csv');

    $res = $this->postJson('/api/v1/products/import-inventory', [
        'file' => $file,
    ]);

    $res->assertOk();
    $res->assertJsonPath('updated', 1);
    $res->assertJsonPath('match_column', 'Variant SKU');
    $res->assertJsonPath('qty_column', 'Variant Inventory Qty');
    $res->assertJsonPath('missing_in_system.0', 'SKU-NOT-IN-DB');

    $this->assertDatabaseHas('products', [
        'sku' => 'MS-D20',
        'available_qty' => 5,
    ]);

    $notUpdated = $res->json('not_updated') ?? [];
    expect($notUpdated)->toBeArray();
    expect(collect($notUpdated)->pluck('sku')->all())->toContain('MS-D15');
});

it('rejects a Shopify CSV missing required inventory columns', function (): void {
    $csv = implode("\n", [
        'Handle,Title',
        'x,Thing',
    ]);
    $file = UploadedFile::fake()->createWithContent('shopify.csv', $csv, 'text/csv');

    $this->postJson('/api/v1/products/import-inventory', [
        'file' => $file,
    ])->assertStatus(422)->assertJson([
        'message' => 'Missing required column: Variant SKU',
    ]);
});

it('treats -1 inventory qty as 0 and excludes archived products from not-updated list', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000040101',
        'sku' => 'INV-NEG-1',
        'description' => 'Negative one test',
        'available_qty' => 9,
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000040102',
        'sku' => 'INV-ARCH-1',
        'description' => 'Archived product',
        'available_qty' => 7,
        'archived_at' => now(),
    ]);

    $csv = implode("\n", [
        'Handle,Variant SKU,Variant Inventory Qty,Variant Price',
        'x,INV-NEG-1,-1,6.99',
    ]);
    $file = UploadedFile::fake()->createWithContent('shopify.csv', $csv, 'text/csv');

    $res = $this->postJson('/api/v1/products/import-inventory', [
        'file' => $file,
    ]);

    $res->assertOk();
    $res->assertJsonPath('updated', 1);

    $this->assertDatabaseHas('products', [
        'sku' => 'INV-NEG-1',
        'available_qty' => 0,
    ]);

    $notUpdated = $res->json('not_updated') ?? [];
    expect($notUpdated)->toBeArray();
    expect(collect($notUpdated)->pluck('sku')->all())->not->toContain('INV-ARCH-1');
});


