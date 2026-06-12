<?php

declare(strict_types=1);

use App\Enums\PlamodPreorderManufacturerFilterDecision;
use App\Enums\PlamodPreorderManufacturerFilterType;
use App\Models\PlamodPreorderManufacturerFilter;
use App\Services\Plamod\PlamodPreorderManufacturerFilterDiscoverService;
use App\Services\Products\Http\PlamodScraper;

it('discovers filters and preserves existing decisions', function (): void {
    PlamodPreorderManufacturerFilter::query()->create([
        'manufacturer_id' => 1,
        'filter_type' => PlamodPreorderManufacturerFilterType::Series,
        'name' => 'Mobile Suit Gundam',
        'decision' => PlamodPreorderManufacturerFilterDecision::Exclude,
        'plamod_preorder_count' => 1,
        'last_seen_at' => now()->subDay(),
    ]);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('listManufacturerPreorderFilters')->once()->with(1)->andReturn([
        'ok' => true,
        'series' => [
            ['name' => 'Mobile Suit Gundam', 'preorder_count' => 27, 'other_count' => 12],
            ['name' => 'Pokémon', 'preorder_count' => 24, 'other_count' => null],
        ],
        'category_lines' => [
            ['name' => 'SD Cross Silhouette', 'preorder_count' => 0, 'other_count' => 0],
        ],
    ]);
    app()->instance(PlamodScraper::class, $scraper);

    $result = app(PlamodPreorderManufacturerFilterDiscoverService::class)->discover(1);

    expect($result['ok'])->toBeTrue();
    expect($result['series_discovered'])->toBe(2);

    $msg = PlamodPreorderManufacturerFilter::query()->where('name', 'Mobile Suit Gundam')->first();
    expect($msg?->decision)->toBe(PlamodPreorderManufacturerFilterDecision::Exclude);
    expect($msg?->plamod_preorder_count)->toBe(27);

    $pokemon = PlamodPreorderManufacturerFilter::query()->where('name', 'Pokémon')->first();
    expect($pokemon?->decision)->toBe(PlamodPreorderManufacturerFilterDecision::Include);

    $sd = PlamodPreorderManufacturerFilter::query()
        ->where('name', 'SD Cross Silhouette')
        ->where('filter_type', PlamodPreorderManufacturerFilterType::CategoryLine)
        ->first();
    expect($sd?->decision)->toBe(PlamodPreorderManufacturerFilterDecision::Include);
});
