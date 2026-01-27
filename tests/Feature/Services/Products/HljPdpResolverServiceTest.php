<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Products\Hlj\HljPdpResolverService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // Avoid ExternalHtmlClient throttling sleeps in unit/feature tests.
    RateLimiter::clear('price_research:site:hlj');
    config([
        'price_research.rate_limit.per_site_per_minute' => 120,
        'price_research.rate_limit.per_site_overrides.hlj' => 120,
    ]);
});

it('resolves HLJ PDP by matching JAN Code when multiple candidates exist', function (): void {
    $product = Product::query()->create([
        'sku' => 'JAN-MATCH-1',
        'barcode' => '4549660222361',
        'description' => 'MG 1/100 ZZ Gundam Ver.Ka',
        'vendor' => 'Plamod',
    ]);

    $searchHtml = implode('', [
        '<a href="/wrong-kit-ban11111">Wrong</a>',
        '<a href="/1-100-scale-mg-zz-gundam-ver-ka-w-premium-decal-bann22236">Correct</a>',
    ]);

    $wrongUrl = 'https://www.hlj.com/wrong-kit-ban11111';
    $correctUrl = 'https://www.hlj.com/1-100-scale-mg-zz-gundam-ver-ka-w-premium-decal-bann22236';

    $wrongPdp = <<<HTML
<!doctype html><html><head><meta property="og:title" content="ZZ Gundam Something Else" /></head>
<body><div class="product-detail">JAN Code: 0000000000000</div></body></html>
HTML;

    $correctPdp = <<<HTML
<!doctype html><html><head><meta property="og:title" content="MG ZZ Gundam Ver.Ka w/Premium Decal" /></head>
<body><div class="product-detail">JAN Code: 4549660222361</div></body></html>
HTML;

    Http::fake(function (Illuminate\Http\Client\Request $req) use ($searchHtml, $wrongUrl, $correctUrl, $wrongPdp, $correctPdp) {
        $url = (string) $req->url();
        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=')) {
            return Http::response($searchHtml, 200);
        }
        if ($url === $wrongUrl) {
            return Http::response($wrongPdp, 200);
        }
        if ($url === $correctUrl) {
            return Http::response($correctPdp, 200);
        }
        return Http::response('not_found', 404);
    });

    /** @var HljPdpResolverService $svc */
    $svc = app(HljPdpResolverService::class);
    $url = $svc->resolvePdpUrlForProduct($product);

    expect($url)->toBe($correctUrl);
});

it('resolves HLJ PDP by title similarity when JAN code is missing', function (): void {
    $product = Product::query()->create([
        'sku' => 'NO-JAN-1',
        'barcode' => null,
        'description' => 'MG 1/100 ZZ Gundam Ver.Ka',
        'vendor' => 'Plamod',
    ]);

    $searchHtml = implode('', [
        '<a href="/hg-zz-gundam-ban99999">HG ZZ Gundam</a>',
        '<a href="/1-100-scale-mg-zz-gundam-ver-ka-w-premium-decal-bann22236">MG ZZ Gundam Ver.Ka</a>',
    ]);

    $hgUrl = 'https://www.hlj.com/hg-zz-gundam-ban99999';
    $mgUrl = 'https://www.hlj.com/1-100-scale-mg-zz-gundam-ver-ka-w-premium-decal-bann22236';

    $hgPdp = '<!doctype html><html><head><meta property="og:title" content="HG ZZ Gundam" /></head><body></body></html>';
    $mgPdp = '<!doctype html><html><head><meta property="og:title" content="MG ZZ Gundam Ver.Ka w/Premium Decal" /></head><body></body></html>';

    Http::fake(function (Illuminate\Http\Client\Request $req) use ($searchHtml, $hgUrl, $mgUrl, $hgPdp, $mgPdp) {
        $url = (string) $req->url();
        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=')) {
            return Http::response($searchHtml, 200);
        }
        if ($url === $hgUrl) {
            return Http::response($hgPdp, 200);
        }
        if ($url === $mgUrl) {
            return Http::response($mgPdp, 200);
        }
        return Http::response('not_found', 404);
    });

    /** @var HljPdpResolverService $svc */
    $svc = app(HljPdpResolverService::class);
    $url = $svc->resolvePdpUrlForProduct($product);

    expect($url)->toBe($mgUrl);
});

it('resolves the correct PDP by JAN even if the product title query would match a different kit', function (): void {
    $product = Product::query()->create([
        'sku' => '5063038',
        'barcode' => '4573102630384',
        'description' => '1/144 HGUC V GUNDAM',
        'vendor' => 'Plamod',
    ]);

    // Search results include both the wrong and correct candidate.
    $searchHtml = implode('', [
        '<a href="/1-144-scale-hguc-msn-06s-sinanju-banh588135-up">Sinanju</a>',
        '<a href="/1-144-scale-hguc-victory-gundam-banh630384-up">Victory Gundam</a>',
    ]);

    $sinanjuUrl = 'https://www.hlj.com/1-144-scale-hguc-msn-06s-sinanju-banh588135-up';
    $victoryUrl = 'https://www.hlj.com/1-144-scale-hguc-victory-gundam-banh630384-up';

    $sinanjuPdp = '<!doctype html><html><head><meta property="og:title" content="HGUC MSN-06S Sinanju | HLJ.com" /></head><body><div>JAN Code: 9999999999999</div></body></html>';
    $victoryPdp = '<!doctype html><html><head><meta property="og:title" content="HGUC Victory Gundam | HLJ.com" /></head><body><div>JAN Code: 4573102630384</div></body></html>';

    Http::fake(function (Illuminate\Http\Client\Request $req) use ($searchHtml, $sinanjuUrl, $victoryUrl, $sinanjuPdp, $victoryPdp) {
        $url = (string) $req->url();
        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=')) {
            return Http::response($searchHtml, 200);
        }
        if ($url === $sinanjuUrl) {
            return Http::response($sinanjuPdp, 200);
        }
        if ($url === $victoryUrl) {
            return Http::response($victoryPdp, 200);
        }
        return Http::response('not_found', 404);
    });

    /** @var HljPdpResolverService $svc */
    $svc = app(HljPdpResolverService::class);
    $url = $svc->resolvePdpUrlForProduct($product);

    expect($url)->toBe($victoryUrl);
});

it('can resolve HLJ PDP by dropping model-code tokens from the query (e.g. MBF-02VV)', function (): void {
    $product = Product::query()->create([
        'sku' => '5063530',
        'barcode' => '4573102635303',
        'description' => 'MG 1/100 MBF-02VV GUNDAM ASTRAY TURN RED',
        'vendor' => 'Plamod',
    ]);

    $pdpUrl = 'https://www.hlj.com/1-100-scale-mg-gundam-astray-turn-red-ban00000';

    Http::fake(function (Illuminate\Http\Client\Request $req) use ($pdpUrl) {
        $url = (string) $req->url();

        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=')) {
            $q = (string) parse_url($url, PHP_URL_QUERY);
            parse_str($q, $params);
            $word = (string) ($params['Word'] ?? '');
            $decoded = rawurldecode($word);

            // Full query (with model code) returns nothing.
            if (str_contains($decoded, 'MBF-02VV')) {
                return Http::response('<html><body>No results</body></html>', 200);
            }

            // Cleaned query (no model code) returns the correct PDP.
            if (str_contains($decoded, 'ASTRAY') && str_contains($decoded, 'TURN') && str_contains($decoded, 'RED')) {
                return Http::response('<a href="/1-100-scale-mg-gundam-astray-turn-red-ban00000">Astray Turn Red</a>', 200);
            }

            return Http::response('<html><body>No results</body></html>', 200);
        }

        if ($url === $pdpUrl) {
            return Http::response(
                '<!doctype html><html><head><meta property="og:title" content="MG Gundam Astray Turn Red | HLJ.com" /></head><body><div>JAN Code: 4573102635303</div></body></html>',
                200
            );
        }

        return Http::response('not_found', 404);
    });

    /** @var HljPdpResolverService $svc */
    $svc = app(HljPdpResolverService::class);
    $url = $svc->resolvePdpUrlForProduct($product);

    expect($url)->toBe($pdpUrl);
});

it('does not pick an unrelated HLJ PDP when the grade does not match (e.g. MG product should not resolve to non-MG)', function (): void {
    $product = Product::query()->create([
        'sku' => '5063530',
        'barcode' => '4573102635303',
        'description' => 'MG 1/100 MBF-02VV GUNDAM ASTRAY TURN RED',
        'vendor' => 'Plamod',
    ]);

    $weaponsUrl = 'https://www.hlj.com/gundam-weapons-seed-destiny-astray-r-turn-red-hbj60934';
    $mgUrl = 'https://www.hlj.com/1-100-scale-mg-gundam-astray-turn-red-ban00000';

    Http::fake(function (Illuminate\Http\Client\Request $req) use ($weaponsUrl, $mgUrl) {
        $url = (string) $req->url();

        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=')) {
            // Return both: an unrelated weapons set, and an MG kit.
            return Http::response(implode('', [
                '<a href="/gundam-weapons-seed-destiny-astray-r-turn-red-hbj60934">Weapons set</a>',
                '<a href="/1-100-scale-mg-gundam-astray-turn-red-ban00000">MG Astray Turn Red</a>',
            ]), 200);
        }

        if ($url === $weaponsUrl) {
            // No MG token in the title.
            return Http::response('<!doctype html><html><head><meta property="og:title" content="Gundam Weapons SEED Destiny Astray R Turn Red | HLJ.com" /></head><body></body></html>', 200);
        }

        if ($url === $mgUrl) {
            return Http::response('<!doctype html><html><head><meta property="og:title" content="MG Gundam Astray Turn Red | HLJ.com" /></head><body></body></html>', 200);
        }

        return Http::response('not_found', 404);
    });

    /** @var HljPdpResolverService $svc */
    $svc = app(HljPdpResolverService::class);
    $url = $svc->resolvePdpUrlForProduct($product);

    expect($url)->toBe($mgUrl);
});

it('returns null when only a single candidate exists but it does not meet the grade constraint', function (): void {
    $product = Product::query()->create([
        'sku' => '5063530',
        'barcode' => '4573102635303',
        'description' => 'MG 1/100 MBF-02VV GUNDAM ASTRAY TURN RED',
        'vendor' => 'Plamod',
    ]);

    $weaponsUrl = 'https://www.hlj.com/gundam-weapons-seed-destiny-astray-r-turn-red-hbj60934';

    Http::fake(function (Illuminate\Http\Client\Request $req) use ($weaponsUrl) {
        $url = (string) $req->url();

        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=')) {
            return Http::response('<a href="/gundam-weapons-seed-destiny-astray-r-turn-red-hbj60934">Weapons set</a>', 200);
        }

        if ($url === $weaponsUrl) {
            return Http::response('<!doctype html><html><head><meta property="og:title" content="Gundam Weapons SEED Destiny Astray R Turn Red | HLJ.com" /></head><body></body></html>', 200);
        }

        return Http::response('not_found', 404);
    });

    /** @var HljPdpResolverService $svc */
    $svc = app(HljPdpResolverService::class);
    $url = $svc->resolvePdpUrlForProduct($product);

    expect($url)->toBeNull();
});

it('can resolve HLJ PDP using a human query that drops grade/scale/model-code and preserves Ver.Ka', function (): void {
    $product = Product::query()->create([
        'sku' => '5061591',
        'barcode' => null, // simulate missing JAN
        'description' => 'Master Grade (MG) 1/100 RX-93-ν2 Hi-Nu Gundam Ver.Ka',
        'vendor' => 'Plamod',
    ]);

    $pdpUrl = 'https://www.hlj.com/1-100-scale-mg-hi-nu-gundam-ver-ka-banh615916-up';

    Http::fake(function (Illuminate\Http\Client\Request $req) use ($pdpUrl) {
        $url = (string) $req->url();

        if (str_starts_with($url, 'https://www.hlj.com/search/?Word=')) {
            $query = (string) parse_url($url, PHP_URL_QUERY);
            parse_str($query, $params);
            $word = rawurldecode((string) ($params['Word'] ?? ''));

            // Only the human query should return results.
            $looksHuman = str_contains($word, 'Hi')
                && str_contains($word, 'Nu')
                && str_contains($word, 'Gundam')
                && str_contains($word, 'verka')
                && ! str_contains($word, 'RX')
                && ! str_contains($word, 'MG')
                && ! str_contains($word, '1/100');
            if ($looksHuman) {
                return Http::response('<a href="/1-100-scale-mg-hi-nu-gundam-ver-ka-banh615916-up">MG Hi-Nu Gundam Ver.Ka</a>', 200);
            }

            return Http::response('<html><body>No results</body></html>', 200);
        }

        if ($url === $pdpUrl) {
            return Http::response('<!doctype html><html><head><meta property="og:title" content="MG Hi-Nu Gundam Ver.Ka | HLJ.com" /></head><body></body></html>', 200);
        }

        return Http::response('not_found', 404);
    });

    /** @var HljPdpResolverService $svc */
    $svc = app(HljPdpResolverService::class);
    $url = $svc->resolvePdpUrlForProduct($product);

    expect($url)->toBe($pdpUrl);
});

