<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalContent;

it('returns empty Plamod data when product has not been synced', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050010',
        'sku' => '5063838',
        'description' => 'Plamod product',
        'vendor' => 'Plamod',
    ]);

    $res = $this->getJson('/api/v1/products/00000000-0000-0000-0000-000000050010/plamod');

    $res->assertOk();
    $res->assertJsonPath('data.source', 'plamod');
    $res->assertJsonPath('data.content', null);
    $res->assertJsonPath('data.assets', []);
});

it('includes content source_url when present', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050011',
        'sku' => 'HLJ-TEST-1',
        'description' => 'Test product',
        'vendor' => 'Plamod',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'source_url' => 'https://www.hlj.com/1-100-scale-mg-gundam-gp03s-ban901788',
        'title' => 'HLJ title',
        'description_html' => '<p>desc</p>',
        'attributes_json' => null,
    ]);

    $res = $this->getJson('/api/v1/products/00000000-0000-0000-0000-000000050011/plamod');

    $res->assertOk();
    $res->assertJsonPath('data.content.source', 'hlj');
    $res->assertJsonPath('data.content.source_url', 'https://www.hlj.com/1-100-scale-mg-gundam-gp03s-ban901788');
});


