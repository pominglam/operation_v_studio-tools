<?php

declare(strict_types=1);

use App\Models\PlamodPreorder;
use App\Services\Plamod\PlamodPreorderMissingImageEnrichService;
use App\Services\Products\Http\PlamodScraper;

it('fills source_image_url from pdp enrich for active rows missing images', function (): void {
    PlamodPreorder::query()->create([
        'sku' => 'IMG001',
        'product_name' => 'Missing Image Kit',
        'source_image_url' => null,
        'dropped_at' => null,
        'last_seen_at' => now(),
    ]);
    PlamodPreorder::query()->create([
        'sku' => 'IMG002',
        'product_name' => 'Has Image Kit',
        'source_image_url' => 'https://example.com/existing.png',
        'dropped_at' => null,
        'last_seen_at' => now(),
    ]);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('enrichPreorderPdpFields')
        ->once()
        ->with(['IMG001'])
        ->andReturn([
            'ok' => true,
            'results' => [
                'IMG001' => [
                    'image_url' => 'https://images.plamod.com/kit.png',
                ],
            ],
        ]);
    app()->instance(PlamodScraper::class, $scraper);

    $result = app(PlamodPreorderMissingImageEnrichService::class)->enrichActiveRowsMissingImageUrl();

    expect($result)->toBe([
        'attempted' => 1,
        'enriched' => 1,
        'failed' => 0,
    ]);
    expect(PlamodPreorder::query()->where('sku', 'IMG001')->value('source_image_url'))
        ->toBe('https://images.plamod.com/kit.png');
});
