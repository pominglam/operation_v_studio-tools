<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
use App\Models\ProductSellingPrice;
use Illuminate\Support\Facades\DB;

it('deletes selected products by uuid', function (): void {
    $p1 = Product::query()->create([
        'sku' => 'BULK-1',
        'description' => 'Bulk 1',
    ]);
    $p2 = Product::query()->create([
        'sku' => 'BULK-2',
        'description' => 'Bulk 2',
    ]);

    $response = $this->postJson('/api/v1/products/bulk-delete', [
        'ids' => [$p1->uuid],
    ]);

    $response->assertOk()->assertJson([
        'deleted' => 1,
    ]);

    $this->assertDatabaseMissing('products', ['sku' => 'BULK-1']);
    $this->assertDatabaseHas('products', ['sku' => 'BULK-2']);
});

it('bulk delete removes dependent PDP/selling data', function (): void {
    $p = Product::query()->create([
        'sku' => 'BULK-DEP-1',
        'description' => 'Has deps',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '10.00',
        'currency' => 'CAD',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'title' => 'HLJ',
        'description_html' => '<p>desc</p>',
        'attributes_json' => [],
    ]);

    ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/x.png',
        'filename' => 'x.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1,
        'checksum_sha256' => null,
    ]);

    DB::table('product_price_quotes')->insert([
        'product_id' => $p->id,
        'site_key' => 'example',
        'site_name' => 'Example',
        'status' => 'found',
        'currency' => 'CAD',
        'price' => '1.23',
        'product_url' => 'https://example.com',
        'error_message' => null,
        'fetched_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/v1/products/bulk-delete', [
        'ids' => [$p->uuid],
    ])->assertOk()->assertJson(['deleted' => 1]);

    $this->assertDatabaseMissing('products', ['uuid' => $p->uuid]);
    $this->assertDatabaseMissing('product_selling_prices', ['product_id' => $p->id]);
    $this->assertDatabaseMissing('product_external_contents', ['product_id' => $p->id]);
    $this->assertDatabaseMissing('product_external_assets', ['product_id' => $p->id]);
    $this->assertDatabaseMissing('product_price_quotes', ['product_id' => $p->id]);
});

it('validates ids for bulk delete', function (): void {
    $response = $this->postJson('/api/v1/products/bulk-delete', [
        'ids' => [],
    ]);

    $response->assertStatus(422);
});
