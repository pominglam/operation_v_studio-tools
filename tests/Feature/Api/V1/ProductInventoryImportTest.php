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


