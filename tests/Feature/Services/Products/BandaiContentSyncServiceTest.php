<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Products\Bandai\BandaiContentSyncService;
use Illuminate\Support\Facades\Http;

it('can sync Bandai PDP for a product by dropping model-code tokens from the search query', function (): void {
    $product = Product::query()->create([
        'sku' => '5063530',
        'barcode' => '4573102635303',
        'description' => 'MG 1/100 MBF-02VV GUNDAM ASTRAY TURN RED',
        'vendor' => 'Plamod',
    ]);

    $pdpUrl = 'https://global.bandai-hobby.net/en-us/item/00_0000/';
    $cmsApiBase = 'https://cmsapi-global-frontend.bandai-hobby.net/site/api/hobby/Product/list';
    $imageUrl = 'https://global.bandai-hobby.net/images/test-bandai.jpg';

    $pdpHtml = <<<'HTML'
<!doctype html>
<html>
  <body>
    <main>
      <h1>MG 1/100 GUNDAM ASTRAY TURN RED</h1>
      <div class="pg-products__sliderMainWrap">
        <a href="https://global.bandai-hobby.net/images/test-bandai.jpg">
          <img alt="MG 1/100 GUNDAM ASTRAY TURN RED" src="https://global.bandai-hobby.net/images/test-bandai.jpg" />
        </a>
      </div>
    </main>
  </body>
</html>
HTML;

    Http::fake(function (Illuminate\Http\Client\Request $req) use ($cmsApiBase, $pdpUrl, $pdpHtml, $imageUrl) {
        $url = (string) $req->url();

        // Ensure we never hit Bandai search with MBF-02VV (it should be removed).
        if (str_starts_with($url, 'https://global.bandai-hobby.net/en-us/search/?')) {
            expect($url)->not->toContain('MBF-02VV');
            expect($url)->not->toContain('1%2F100');

            return Http::response('<html><body>token=deadbeefdeadbeefdeadbeefdeadbeef</body></html>', 200);
        }

        if (str_starts_with($url, $cmsApiBase)) {
            return Http::response([
                'data' => [
                    'product_list' => [
                        ['url' => $pdpUrl, 'title' => 'MG 1/100 GUNDAM ASTRAY TURN RED'],
                    ],
                ],
            ], 200);
        }

        if ($url === $pdpUrl) {
            return Http::response($pdpHtml, 200);
        }

        if ($url === $imageUrl) {
            return Http::response('fakejpegbytes', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not_found', 404);
    });

    /** @var BandaiContentSyncService $svc */
    $svc = app(BandaiContentSyncService::class);
    $ok = $svc->syncForProduct($product);

    expect($ok)->toBeTrue();
});
