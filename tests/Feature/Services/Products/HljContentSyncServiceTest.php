<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
use App\Services\Products\Hlj\HljContentSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../../Support/PngTestUtils.php';

it('syncs HLJ description and images for a product', function (): void {
    Storage::fake('local');

    $product = Product::query()->create([
        'sku' => 'HLJ-1',
        'barcode' => null,
        'description' => 'Test HLJ Product',
        'vendor' => 'Plamod',
    ]);

    $searchHtml = '<a href="/test-hlj-product-ban12345">Result</a>';
    $pdpUrl = 'https://www.hlj.com/test-hlj-product-ban12345';
    $img1 = 'https://www.hlj.com/media/catalog/product/a/b/ab123_main.png';
    $img2 = 'https://www.hlj.com/media/catalog/product/a/b/ab123_02.png';

    $imgBytes1 = buildPngBytes(800, 800, str_shuffle(str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 1200)));
    $imgBytes2 = buildPngBytes(800, 800, str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz', 1200)));

    $pdpHtml = <<<HTML
<!doctype html>
<html>
<head>
  <meta property="og:title" content="HLJ Test Title" />
  <meta property="og:image" content="{$img1}" />
  <script type="application/ld+json">
    {"@context":"https://schema.org","@type":"Product","name":"HLJ Test Title","image":["{$img1}","{$img2}"]}
  </script>
</head>
<body>
  <div class="product-description"><h3>Description</h3><p>Nice kit.</p></div>
</body>
</html>
HTML;

    Http::fake(function (Illuminate\Http\Client\Request $req) use ($searchHtml, $pdpUrl, $pdpHtml, $img1, $img2, $imgBytes1, $imgBytes2) {
        $url = (string) $req->url();

        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=') || str_starts_with($url, 'https://www.hlj.com/search/?q=')) {
            return Http::response($searchHtml, 200);
        }
        if ($url === $pdpUrl) {
            return Http::response($pdpHtml, 200);
        }
        if ($url === $img1) {
            return Http::response($imgBytes1, 200, ['Content-Type' => 'image/png']);
        }
        if ($url === $img2) {
            return Http::response($imgBytes2, 200, ['Content-Type' => 'image/png']);
        }

        return Http::response('not_found', 404);
    });

    /** @var HljContentSyncService $svc */
    $svc = app(HljContentSyncService::class);
    $svc->syncForProduct($product);

    $content = ProductExternalContent::query()
        ->where('product_id', $product->id)
        ->where('source', 'hlj')
        ->first();
    expect($content)->not->toBeNull();
    expect($content?->title)->toBe('HLJ Test Title');
    expect($content?->description_html)->toContain('Nice kit');

    $assets = ProductExternalAsset::query()
        ->where('product_id', $product->id)
        ->where('source', 'hlj')
        ->orderBy('sort_order')
        ->get();

    expect($assets)->toHaveCount(2);
    expect($assets[0]->storage_path)->toContain('hlj/images/HLJ-1/');
    expect($assets[1]->storage_path)->toContain('hlj/images/HLJ-1/');
    Storage::disk('local')->assertExists($assets[0]->storage_path);
    Storage::disk('local')->assertExists($assets[1]->storage_path);
});

it('clears previously stored HLJ content/assets when a recrawl can no longer resolve a PDP', function (): void {
    Storage::fake('local');

    $product = Product::query()->create([
        'sku' => 'HLJ-CLEAR-1',
        'barcode' => '4573102635303',
        'description' => 'MG 1/100 MBF-02VV GUNDAM ASTRAY TURN RED',
        'vendor' => 'Plamod',
    ]);

    // Seed stale content/assets (e.g. previously matched the wrong PDP).
    ProductExternalContent::query()->create([
        'product_id' => (int) $product->id,
        'source' => 'hlj',
        'source_url' => 'https://www.hlj.com/some-wrong-pdp',
        'title' => 'Wrong title',
        'description_html' => '<p>Wrong</p>',
        'attributes_json' => null,
    ]);

    Storage::disk('local')->put('hlj/images/HLJ-CLEAR-1/hlj-hlj-clear-1-1.jpg', str_repeat('x', 20000));
    ProductExternalAsset::query()->create([
        'product_id' => (int) $product->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => 'hlj/images/HLJ-CLEAR-1/hlj-hlj-clear-1-1.jpg',
        'filename' => 'hlj-hlj-clear-1-1.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 20000,
        'sort_order' => 1,
    ]);

    // Make HLJ search return no candidates so the resolver returns null (cannot find PDP).
    Http::fake(function (Illuminate\Http\Client\Request $req) {
        $url = (string) $req->url();
        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=') || str_starts_with($url, 'https://www.hlj.com/search/?q=')) {
            return Http::response('<html><body>No results</body></html>', 200);
        }

        return Http::response('not_found', 404);
    });

    /** @var HljContentSyncService $svc */
    $svc = app(HljContentSyncService::class);
    $svc->syncForProduct($product);

    $content = ProductExternalContent::query()
        ->where('product_id', $product->id)
        ->where('source', 'hlj')
        ->first();

    expect($content)->not->toBeNull();
    expect($content?->source_url)->toBeNull();
    expect($content?->title)->toBeNull();
    expect($content?->description_html)->toBeNull();

    expect(ProductExternalAsset::query()->where('product_id', $product->id)->where('source', 'hlj')->count())->toBe(0);
});

it('clears previously stored HLJ assets when a PDP resolves but no images can be downloaded', function (): void {
    Storage::fake('local');

    $product = Product::query()->create([
        'sku' => 'HLJ-CLEAR-DL-1',
        'barcode' => '4573102663085',
        'description' => 'MG 1/100 NARRATIVE GUNDAM C-PACKS Ver.Ka',
        'vendor' => 'Plamod',
    ]);

    // Seed stale assets (e.g. prior bad crawl).
    Storage::disk('local')->put('hlj/images/HLJ-CLEAR-DL-1/old.jpg', str_repeat('x', 20000));
    ProductExternalAsset::query()->create([
        'product_id' => (int) $product->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => 'hlj/images/HLJ-CLEAR-DL-1/old.jpg',
        'filename' => 'old.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 20000,
        'sort_order' => 1,
    ]);

    $searchHtml = '<a href="/1-100-scale-mg-narrative-gundam-c-packs-ver-ka-banh663085">Result</a>';
    $pdpUrl = 'https://www.hlj.com/1-100-scale-mg-narrative-gundam-c-packs-ver-ka-banh663085';
    $img = 'https://www.hlj.com/productimages/ban/banh663085_0.png';

    $pdpHtml = <<<HTML
<!doctype html>
<html>
<head>
  <meta property="og:title" content="HLJ Test Title" />
  <meta property="og:image" content="{$img}" />
</head>
<body>
  <div class="product-description"><h3>Description</h3><p>Nice kit.</p></div>
</body>
</html>
HTML;

    // Image download returns image/* but below the downloader's min size threshold, so no assets are persisted.
    Http::fake(function (Illuminate\Http\Client\Request $req) use ($searchHtml, $pdpUrl, $pdpHtml, $img) {
        $url = (string) $req->url();
        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=') || str_starts_with($url, 'https://www.hlj.com/search/?q=')) {
            return Http::response($searchHtml, 200);
        }
        if ($url === $pdpUrl) {
            return Http::response($pdpHtml, 200);
        }
        if ($url === $img) {
            return Http::response(str_repeat('a', 5000), 200, ['Content-Type' => 'image/png']);
        }

        return Http::response('not_found', 404);
    });

    /** @var HljContentSyncService $svc */
    $svc = app(HljContentSyncService::class);
    $svc->syncForProduct($product);

    expect(ProductExternalAsset::query()->where('product_id', $product->id)->where('source', 'hlj')->count())->toBe(0);
});

it('does not persist a banner/logo image even when it is served at a product-image URL', function (): void {
    Storage::fake('local');

    $product = Product::query()->create([
        'sku' => 'HLJ-BANNER-1',
        'barcode' => '4573102640154',
        'description' => 'MG 1/100 ZETA GUNDAM Ver.Ka',
        'vendor' => 'Plamod',
    ]);

    // Seed garbage assets that must be cleared by the recrawl.
    Storage::disk('local')->put('hlj/images/HLJ-BANNER-1/old.jpg', str_repeat('x', 20000));
    ProductExternalAsset::query()->create([
        'product_id' => (int) $product->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => 'hlj/images/HLJ-BANNER-1/old.jpg',
        'filename' => 'old.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 20000,
        'sort_order' => 1,
    ]);

    $searchHtml = '<a href="/1-100-scale-mg-zeta-gundam-ver-ka-bans64015">Result</a>';
    $pdpUrl = 'https://www.hlj.com/1-100-scale-mg-zeta-gundam-ver-ka-bans64015';
    $imgUrl = 'https://www.hlj.com/productimages/ban/bans64015_0.png';

    // A “DHL-style” banner: very wide and extremely compressible, but served from a product image URL.
    $bannerBytes = buildPngBytes(1200, 300, str_repeat('A', 30_000));

    $pdpHtml = <<<HTML
<!doctype html>
<html>
  <head>
    <meta property="og:title" content="HLJ Zeta Title" />
    <meta property="og:image" content="{$imgUrl}" />
    <script type="application/ld+json">
      {"@context":"https://schema.org","@type":"Product","name":"HLJ Zeta Title","gtin13":"4573102640154"}
    </script>
  </head>
  <body>
    <div class="product-description"><h3>Description</h3><p>Nice kit.</p></div>
  </body>
</html>
HTML;

    Http::fake(function (Illuminate\Http\Client\Request $req) use ($searchHtml, $pdpUrl, $pdpHtml, $imgUrl, $bannerBytes) {
        $url = (string) $req->url();
        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=') || str_starts_with($url, 'https://www.hlj.com/search/?q=')) {
            return Http::response($searchHtml, 200);
        }
        if ($url === $pdpUrl) {
            return Http::response($pdpHtml, 200);
        }
        if ($url === $imgUrl) {
            return Http::response($bannerBytes, 200, ['Content-Type' => 'image/png']);
        }

        return Http::response('not_found', 404);
    });

    /** @var HljContentSyncService $svc */
    $svc = app(HljContentSyncService::class);
    $svc->syncForProduct($product);

    expect(ProductExternalAsset::query()->where('product_id', $product->id)->where('source', 'hlj')->count())->toBe(0);
});
