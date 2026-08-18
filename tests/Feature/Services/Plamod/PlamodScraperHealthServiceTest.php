<?php

declare(strict_types=1);

use App\Services\Plamod\PlamodScraperHealthService;
use Illuminate\Support\Facades\Http;

it('rejects outdated plamod scraper without preorders export route', function (): void {
    Http::fake([
        'http://plamod-scraper.test/health' => Http::response(['ok' => true], 200),
    ]);

    $service = new PlamodScraperHealthService('http://plamod-scraper.test');
    $result = $service->assertPreordersExportReady();

    expect($result['ok'])->toBeFalse();
    expect($result['error_message'])->toContain('outdated code');
});

it('accepts plamod scraper with preorders export route', function (): void {
    Http::fake([
        'http://plamod-scraper.test/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /download-zip',
                'POST /export-preorders-csv',
                'POST /export-manufacturer-preorders-csv',
                'POST /export-manufacturer-instock-merged',
                'POST /list-manufacturer-preorders-filters',
                'POST /search-retailer-preorders',
            ],
        ], 200),
    ]);

    $service = new PlamodScraperHealthService('http://plamod-scraper.test');
    $result = $service->assertPreordersExportReady();

    expect($result['ok'])->toBeTrue();
});

it('rejects plamod scraper without restock cart routes', function (): void {
    Http::fake([
        'http://plamod-scraper.test/health' => Http::response([
            'ok' => true,
            'routes' => ['POST /restock-add-to-cart'],
        ], 200),
    ]);

    $service = new PlamodScraperHealthService('http://plamod-scraper.test');

    expect($service->assertRestockCartReady()['ok'])->toBeFalse();
});

it('accepts plamod scraper with every restock cart route', function (): void {
    Http::fake([
        'http://plamod-scraper.test/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /restock-add-to-cart',
                'POST /restock-verify-cart',
                'GET /restock-cart-progress',
            ],
        ], 200),
    ]);

    $service = new PlamodScraperHealthService('http://plamod-scraper.test');

    expect($service->assertRestockCartReady()['ok'])->toBeTrue();
});
