<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
use App\Services\Products\Argama\ArgamaContentSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('syncs argama images and upgrades width to 1000', function (): void {
    Storage::fake('local');

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000880001',
        'sku' => '5063510',
        'description' => 'Master Grade (MG) 1/100 MSN-04 Sazabi Ver.Ka',
        'vendor' => 'AL',
    ]);

    $searchHtml = <<<'HTML'
    <html><body>
      <a href="/products/bandai-master-grade-1-100-msn-04-sazabi-ver-ka">Master Grade (MG) 1/100 MSN-04 Sazabi Ver.Ka</a>
    </body></html>
    HTML;

    $pdpHtml = <<<'HTML'
    <html><body>
      <img src="//argamahobby.com/cdn/shop/products/MG_Sazabi_Ver_Ka_Box.jpg?v=1574753836&width=180" />
      <img src="https://argamahobby.com/cdn/shop/products/MG_Sazabi_Ver_Ka_2.jpg?v=1574753836&width=250" />
    </body></html>
    HTML;

    Http::fake(function (\Illuminate\Http\Client\Request $req) use ($searchHtml, $pdpHtml) {
        $url = $req->url();
        if (str_contains($url, 'argamahobby.com/search?')) {
            return Http::response($searchHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'argamahobby.com/products/bandai-master-grade-1-100-msn-04-sazabi-ver-ka')) {
            return Http::response($pdpHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'MG_Sazabi_Ver_Ka_Box.jpg') && str_contains($url, 'width=1000')) {
            return Http::response('img1', 200, ['Content-Type' => 'image/jpeg']);
        }
        if (str_contains($url, 'MG_Sazabi_Ver_Ka_2.jpg') && str_contains($url, 'width=1000')) {
            return Http::response('img2', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not found', 404);
    });

    app(ArgamaContentSyncService::class)->syncForProduct($product, 'sync-uuid');

    $assets = ProductExternalAsset::query()
        ->where('product_id', '=', $product->id)
        ->where('source', '=', 'argama')
        ->orderBy('id')
        ->get();

    expect($assets)->toHaveCount(2);
    expect((string) ($assets[0]->origin_url ?? ''))->toContain('width=1000');
    expect((string) ($assets[1]->origin_url ?? ''))->toContain('width=1000');

    Storage::disk('local')->assertExists('argama/images/5063510/argama-5063510-1.jpg');
    Storage::disk('local')->assertExists('argama/images/5063510/argama-5063510-2.jpg');

    $content = ProductExternalContent::query()
        ->where('product_id', '=', $product->id)
        ->where('source', '=', 'argama')
        ->first();

    expect($content)->not->toBeNull();
    expect((string) ($content?->source_url ?? ''))->toContain('/products/bandai-master-grade-1-100-msn-04-sazabi-ver-ka');
});

it('filters out theme assets and dedupes shopify size variants', function (): void {
    Storage::fake('local');

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000880002',
        'sku' => '5055457',
        'description' => 'Master Grade (MG) 1/100 MSN-04 Sazabi Ver.Ka',
        'vendor' => 'AL',
    ]);

    $searchHtml = <<<'HTML'
    <html><body>
      <a href="/products/bandai-master-grade-1-100-msn-04-sazabi-ver-ka">Master Grade (MG) 1/100 MSN-04 Sazabi Ver.Ka</a>
    </body></html>
    HTML;

    // Mimic the real Argama PDP: many junk URLs + multiple size variants of the same image.
    $pdpHtml = <<<'HTML'
    <html><body>
      <link rel="stylesheet" href="https://argamahobby.com/cdn/shop/t/43/assets/theme.css?v=1" />
      <script src="https://argamahobby.com/cdn/shop/t/43/assets/element.image.parallax.js"></script>
      <link rel="icon" href="https://argamahobby.com/cdn/shop/files/Favicon_32x32.png?v=1614350937" />
      <img src="https://argamahobby.com/cdn/shop/files/logo_merged_384x216.png?v=1614314727" alt="logo" />
      <a href="https://www.pinterest.com/pin/create/button/?url=https://argamahobby.com/products/bandai-master-grade-1-100-msn-04-sazabi-ver-ka&media=//argamahobby.com/cdn/shop/products/MG_Sazabi_Ver_Ka_Box_1024x.jpg?v=1574753836">Pin it</a>
      <img src="//argamahobby.com/cdn/shop/products/MG_Sazabi_Ver_Ka_Box_1200x776.jpg?v=1574753836" />
      <img src="//argamahobby.com/cdn/shop/products/MG_Sazabi_Ver_Ka_Box_1200x600_crop_center.jpg?v=1574753836" />
      <img src="//argamahobby.com/cdn/shop/products/MG_Sazabi_Ver_Ka_Box_1024x.jpg?v=1574753836" />
      <img src="//argamahobby.com/cdn/shop/products/MG_Sazabi_Ver_Ka_Box.jpg?v=1574753836" />
      <img src="//argamahobby.com/cdn/shop/products/MG_Sazabi_Ver_Ka.01.jpg?v=1574753836" />
      <img src="//argamahobby.com/cdn/shop/products/MG_Sazabi_Ver_Ka.01_400x.jpg?v=1574753836" />
    </body></html>
    HTML;

    Http::fake(function (\Illuminate\Http\Client\Request $req) use ($searchHtml, $pdpHtml) {
        $url = $req->url();
        if (str_contains($url, 'argamahobby.com/search?')) {
            return Http::response($searchHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'argamahobby.com/products/bandai-master-grade-1-100-msn-04-sazabi-ver-ka')) {
            return Http::response($pdpHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, '/cdn/shop/products/') && str_contains($url, 'width=1000')) {
            return Http::response('img', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not found', 404);
    });

    app(ArgamaContentSyncService::class)->syncForProduct($product, 'sync-uuid');

    $assets = ProductExternalAsset::query()
        ->where('product_id', '=', $product->id)
        ->where('source', '=', 'argama')
        ->orderBy('id')
        ->get();

    // Two unique product images: MG_Sazabi_Ver_Ka_Box.jpg + MG_Sazabi_Ver_Ka.01.jpg
    expect($assets)->toHaveCount(2);

    foreach ($assets as $asset) {
        $u = (string) ($asset->origin_url ?? '');
        expect($u)->toContain('/cdn/shop/products/');
        expect($u)->toContain('width=1000');
        expect($u)->not->toContain('/cdn/shop/files/');
        expect($u)->not->toContain('/cdn/shop/t/');
        expect($u)->not->toContain('&media=');
        expect($u)->not->toMatch('/_\d+x\d*\./');
        expect($u)->not->toContain('_crop_center');
    }
});

it('syncs product images stored under shopify files while excluding logos', function (): void {
    Storage::fake('local');

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000880003',
        'sku' => '5069173',
        'description' => 'High Grade (HG) Mobile Suit Gundam GQuuuuuuX 1/144 MS-06 Zaku (GQ)',
        'vendor' => 'AL',
    ]);

    $searchHtml = <<<'HTML'
    <html><body>
      <a href="/products/high-grade-hg-mobile-suit-gundam-gquuuuuux-1-144-zaku-gq">
        High Grade (HG) Mobile Suit Gundam GQuuuuuuX 1/144 MS-06 Zaku (GQ)
      </a>
    </body></html>
    HTML;

    $pdpHtml = <<<'HTML'
    <html><head>
      <meta property="og:image" content="https://argamahobby.com/cdn/shop/files/Zaku_GQ_08_1200x756.jpg?v=1768568556" />
    </head><body>
      <img src="//argamahobby.com/cdn/shop/files/logo_merged_384x216.png?v=1614314727" alt="Argama Hobby" />
      <img src="//argamahobby.com/cdn/shop/files/Favicon_32x32.png?v=1614350937" />
      <img src="//argamahobby.com/cdn/shop/files/Zaku_GQ_08.jpg?v=1768568556&width=800" alt="High Grade (HG) Mobile Suit Gundam GQuuuuuuX 1/144 MS-06 Zaku (GQ)" />
      <img src="//argamahobby.com/cdn/shop/files/Zaku_GQ_01.jpg?v=1768568556&width=800" alt="High Grade (HG) Mobile Suit Gundam GQuuuuuuX 1/144 MS-06 Zaku (GQ)" />
      <img src="//argamahobby.com/cdn/shop/files/Zaku_GQ_01.jpg?v=1768568556&width=180" />
    </body></html>
    HTML;

    Http::fake(function (\Illuminate\Http\Client\Request $req) use ($searchHtml, $pdpHtml) {
        $url = $req->url();
        if (str_contains($url, 'argamahobby.com/search?')) {
            return Http::response($searchHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'argamahobby.com/products/high-grade-hg-mobile-suit-gundam-gquuuuuux-1-144-zaku-gq')) {
            return Http::response($pdpHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, '/cdn/shop/files/Zaku_GQ_') && str_contains($url, 'width=1000')) {
            return Http::response('img', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not found', 404);
    });

    app(ArgamaContentSyncService::class)->syncForProduct($product, 'sync-uuid');

    $assets = ProductExternalAsset::query()
        ->where('product_id', '=', $product->id)
        ->where('source', '=', 'argama')
        ->orderBy('id')
        ->get();

    expect($assets)->toHaveCount(2);

    foreach ($assets as $asset) {
        $u = (string) ($asset->origin_url ?? '');
        expect($u)->toContain('/cdn/shop/files/Zaku_GQ_');
        expect($u)->toContain('width=1000');
        expect($u)->not->toContain('logo');
        expect($u)->not->toContain('Favicon');
    }
});
