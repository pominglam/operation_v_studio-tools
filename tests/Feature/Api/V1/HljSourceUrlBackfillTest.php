<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalContent;
use Illuminate\Support\Facades\Http;

it('backfills HLJ content source_url to a PDP (not a search URL) when missing', function (): void {
    Http::fake([
        'https://www.hlj.com/search/*' => Http::response(
            '<a href="/bb-368-00-gundam-seven-sword-g-ban999999">BB368</a>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050777',
        'sku' => '0170796',
        'barcode' => '4573102606860',
        'description' => 'BB368 OO Gundam Seven Sword G',
        'vendor' => 'Plamod',
    ]);

    $c = ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'source_url' => null,
        'title' => 'BB #368 00 (Double O) Gundam Seven Sword /G',
        'description_html' => '<p>desc</p>',
        'attributes_json' => null,
    ]);

    $res = $this->getJson('/api/v1/products/00000000-0000-0000-0000-000000050777/plamod');
    $res->assertOk();

    $url = $res->json('data.content.source_url');
    expect($url)->toBeString();
    expect($url)->toContain('https://www.hlj.com/');
    expect($url)->not->toContain('/search/?');
    expect($url)->toBe('https://www.hlj.com/bb-368-00-gundam-seven-sword-g-ban999999');

    $this->assertDatabaseHas('product_external_contents', [
        'id' => $c->id,
        'source_url' => 'https://www.hlj.com/bb-368-00-gundam-seven-sword-g-ban999999',
    ]);
});


