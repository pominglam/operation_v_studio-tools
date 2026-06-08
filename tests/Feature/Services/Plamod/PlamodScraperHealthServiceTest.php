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
                'POST /search-retailer-preorders',
            ],
        ], 200),
    ]);

    $service = new PlamodScraperHealthService('http://plamod-scraper.test');
    $result = $service->assertPreordersExportReady();

    expect($result['ok'])->toBeTrue();
});
