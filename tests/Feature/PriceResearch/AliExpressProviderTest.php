<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\PriceResearch\FxRateService;
use App\Services\PriceResearch\Http\AliExpressScraperClient;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\Providers\AliExpressProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('normalizes non-CAD AliExpress prices into CAD using FX rates', function (): void {
    Cache::flush();

    Http::fake([
        'http://scraper.test/search-and-scrape' => Http::response([
            'status' => 'found',
            'price' => 10.00,
            'original_price' => 20.00,
            'currency' => 'USD',
            'availability' => 'in_stock',
            'product_url' => 'https://www.aliexpress.com/item/123.html',
        ], 200),
        'https://api.frankfurter.app/*' => Http::response([
            'rates' => [
                'CAD' => 1.25,
            ],
        ], 200),
    ]);

    $product = Product::query()->create([
        'sku' => 'MS-104',
        'barcode' => '111',
        'description' => 'Stedi MS-104',
    ]);

    $provider = new AliExpressProvider(
        new AliExpressScraperClient('http://scraper.test'),
        new FxRateService(new ExternalHtmlClient),
    );

    $result = $provider->lookup($product);

    expect($result->status)->toBe('found');
    expect($result->currency)->toBe('CAD');
    expect($result->price)->toBe(12.50);
    expect($result->originalPrice)->toBe(25.00);
    expect($result->productUrl)->toBe('https://www.aliexpress.com/item/123.html');
});

it('treats blocked_by_antibot from the scraper as an error result (no crash)', function (): void {
    Http::fake([
        'http://scraper.test/search-and-scrape' => Http::response([
            'status' => 'error',
            'error_message' => 'blocked_by_antibot',
        ], 200),
    ]);

    $product = Product::query()->create([
        'sku' => 'MS-104',
        'barcode' => '111',
        'description' => 'Stedi MS-104',
    ]);

    $provider = new AliExpressProvider(
        new AliExpressScraperClient('http://scraper.test'),
        new FxRateService(new ExternalHtmlClient),
    );

    $result = $provider->lookup($product);

    expect($result->status)->toBe('error');
    expect($result->errorMessage)->toBe('blocked_by_antibot');
    expect($result->price)->toBeNull();
});
