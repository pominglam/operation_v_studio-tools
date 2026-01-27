<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;

it('returns multi-source product info (contents + assets) with sources', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070001',
        'sku' => '5068707',
        'description' => 'MG 1/100 GUNDAM BARBATOS LUPUS',
        'vendor' => 'Plamod',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'bandai',
        'source_url' => 'https://global.bandai-hobby.net/en-us/item/01_6764/',
        'title' => 'MG 1/100 GUNDAM BARBATOS LUPUS',
        'description_html' => '<p>Hi</p>',
        'attributes_json' => ['bandai_age_text' => 'over the age of 15'],
    ]);

    ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'bandai',
        'kind' => 'image',
        'storage_path' => 'bandai/images/5068707/x.jpg',
        'filename' => 'x.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 123,
        'checksum_sha256' => null,
        'sort_order' => 1,
    ]);

    $res = $this->getJson('/api/v1/products/00000000-0000-0000-0000-000000070001/product-info');
    $res->assertStatus(200);
    $res->assertJsonPath('data.contents.0.source', 'bandai');
    $res->assertJsonPath('data.assets.0.source', 'bandai');
    $res->assertJsonPath('data.contents.0.source_url', 'https://global.bandai-hobby.net/en-us/item/01_6764/');
});

it('does not return empty source rows (no url/title/desc/attrs) as product-info contents', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070002',
        'sku' => 'EMPTY-SOURCE-1',
        'description' => 'Some product',
        'vendor' => 'Plamod',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'source_url' => null,
        'title' => null,
        'description_html' => null,
        'attributes_json' => null,
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'source_url' => 'https://plamod.com/retailer/products/EMPTY-SOURCE-1',
        'title' => null,
        'description_html' => null,
        'attributes_json' => null,
    ]);

    $res = $this->getJson('/api/v1/products/00000000-0000-0000-0000-000000070002/product-info');
    $res->assertStatus(200);

    $res->assertJsonCount(1, 'data.contents');
    $res->assertJsonPath('data.contents.0.source', 'plamod');
});

