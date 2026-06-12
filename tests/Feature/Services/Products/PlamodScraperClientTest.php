<?php

declare(strict_types=1);

use App\Services\Products\Http\PlamodScraperClient;
use Illuminate\Support\Facades\Http;

it('maps scraper 404 to restart guidance for preorders export', function (): void {
    Http::fake([
        'http://plamod-scraper.test/export-preorders-csv' => Http::response([
            'ok' => false,
            'error_message' => 'Not found',
        ], 404),
    ]);

    $client = new PlamodScraperClient('http://plamod-scraper.test');
    $result = $client->exportPreordersCsv();

    expect($result['ok'])->toBeFalse();
    expect($result['error_message'])->toContain('Restart the pricing-tool-plamod-scraper container');
});

it('surfaces scraper ok false responses from preorders export', function (): void {
    Http::fake([
        'http://plamod-scraper.test/export-preorders-csv' => Http::response([
            'ok' => false,
            'error_message' => 'page.waitForEvent: Timeout',
        ], 200),
    ]);

    $client = new PlamodScraperClient('http://plamod-scraper.test');
    $result = $client->exportPreordersCsv();

    expect($result['ok'])->toBeFalse();
    expect($result['error_message'])->toContain('Timeout');
});

it('posts manufacturer preorder export requests to the scraper', function (): void {
    Http::fake([
        'http://plamod-scraper.test/export-manufacturer-preorders-csv' => Http::response([
            'ok' => true,
            'csv_storage_path' => 'plamod/manufacturer_preorder_exports/bandai.csv',
            'row_count' => 632,
            'has_vigna_sku' => true,
        ], 200),
    ]);

    $client = new PlamodScraperClient('http://plamod-scraper.test');
    $result = $client->exportManufacturerPreordersCsv(1);

    expect($result['ok'])->toBeTrue();
    expect($result['row_count'])->toBe(632);
    expect($result['has_vigna_sku'])->toBeTrue();
});

it('lists manufacturer preorder filters from the scraper', function (): void {
    Http::fake([
        'http://plamod-scraper.test/list-manufacturer-preorders-filters' => Http::response([
            'ok' => true,
            'series' => [['name' => 'Mobile Suit Gundam', 'preorder_count' => 10, 'other_count' => 2]],
            'category_lines' => [['name' => 'SD Cross Silhouette', 'preorder_count' => 0, 'other_count' => 0]],
        ], 200),
    ]);

    $client = new PlamodScraperClient('http://plamod-scraper.test');
    $result = $client->listManufacturerPreorderFilters(1);

    expect($result['ok'])->toBeTrue();
    expect($result['series'][0]['name'])->toBe('Mobile Suit Gundam');
});

it('posts per-series manufacturer export requests to the scraper', function (): void {
    Http::fake([
        'http://plamod-scraper.test/export-manufacturer-preorders-csv' => Http::response([
            'ok' => true,
            'csv_storage_path' => 'plamod/manufacturer_preorder_exports/msg.csv',
            'row_count' => 27,
            'series' => 'Mobile Suit Gundam',
        ], 200),
    ]);

    $client = new PlamodScraperClient('http://plamod-scraper.test');
    $result = $client->exportManufacturerPreordersCsv(1, 'Mobile Suit Gundam');

    expect($result['ok'])->toBeTrue();
    expect($result['row_count'])->toBe(27);
});

it('posts retailer preorder search queries to the scraper', function (): void {
    Http::fake([
        'http://plamod-scraper.test/search-retailer-preorders' => Http::response([
            'ok' => true,
            'results' => [
                'RE 1/100 VIGNA-GHINA' => [
                    'sku' => '0225768',
                    'product_name' => 'RE 1/100 VIGNA-GHINA',
                    'plamod_pdp_url' => 'https://plamod.com/retailer/products/0225768',
                ],
            ],
        ], 200),
    ]);

    $client = new PlamodScraperClient('http://plamod-scraper.test');
    $result = $client->searchRetailerPreorders(['RE 1/100 VIGNA-GHINA']);

    expect($result['ok'])->toBeTrue();
    expect($result['results']['RE 1/100 VIGNA-GHINA']['sku'])->toBe('0225768');
});
