<?php

declare(strict_types=1);

use App\Enums\PlamodPreorderManufacturerFilterDecision;
use App\Enums\PlamodPreorderManufacturerFilterType;
use App\Jobs\Plamod\DiscoverPlamodPreorderManufacturerFiltersJob;
use App\Jobs\Plamod\RunPlamodPreorderLiveSearchJob;
use App\Jobs\Plamod\SyncPlamodPreordersJob;
use App\Models\PlamodPreorder;
use App\Models\PlamodPreorderManufacturerFilter;
use App\Models\Product;
use App\Services\Plamod\PlamodPreorderLiveSearchStore;
use App\Services\Plamod\PlamodPreorderManufacturerFilterDiscoverStore;
use App\Services\Plamod\PlamodPreorderSettingsService;
use App\Services\Products\Http\PlamodScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('lists preorders with is_new and unit selling price', function (): void {
    PlamodPreorder::query()->create([
        'sku' => 'NEW-SKU',
        'product_name' => 'New kit',
        'price_stock' => '10.00',
        'image_download_status' => 'pending',
    ]);

    PlamodPreorder::query()->create([
        'sku' => 'OLD-SKU',
        'product_name' => 'Existing kit',
        'price_stock' => '20.00',
        'image_download_status' => 'pending',
    ]);

    PlamodPreorder::query()->create([
        'sku' => '0225768',
        'product_name' => 'RE 1/100 VIGNA-GHINA',
        'price_stock' => '37.70',
        'price_preorder' => '36.59',
        'quantity_preorder' => 2,
        'image_download_status' => 'pending',
    ]);

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000088801',
        'sku' => 'OLD-SKU',
        'description' => 'Exists',
        'vendor' => 'Plamod',
    ]);

    $this->getJson('/api/v1/preorders?per_page=50')
        ->assertOk()
        ->assertJsonFragment(['sku' => 'NEW-SKU', 'is_new' => true, 'unit_selling_price' => '15.99'])
        ->assertJsonFragment(['sku' => 'OLD-SKU', 'is_new' => false, 'unit_selling_price' => '30.99'])
        ->assertJsonFragment(['sku' => '0225768', 'quantity_preorder' => 2, 'unit_selling_price' => '54.99']);
});

it('filters new_only preorders', function (): void {
    PlamodPreorder::query()->create([
        'sku' => 'ONLY-NEW',
        'product_name' => 'Only new',
        'image_download_status' => 'pending',
    ]);

    PlamodPreorder::query()->create([
        'sku' => 'IN-CAT',
        'product_name' => 'In catalog',
        'image_download_status' => 'pending',
    ]);

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000088802',
        'sku' => 'IN-CAT',
        'description' => 'Exists',
        'vendor' => 'Plamod',
    ]);

    $this->getJson('/api/v1/preorders?new_only=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'ONLY-NEW');
});

it('queues preorder sync job when scraper health is ready', function (): void {
    Queue::fake();

    Http::fake([
        'http://plamod_scraper:3001/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /download-zip',
                'POST /export-preorders-csv',
                'POST /export-manufacturer-preorders-csv',
                'POST /list-manufacturer-preorders-filters',
                'POST /search-retailer-preorders',
            ],
        ], 200),
    ]);

    $this->postJson('/api/v1/preorders/sync')
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonStructure(['data' => ['sync_log_id']]);

    Queue::assertPushed(SyncPlamodPreordersJob::class);
});

it('returns actionable error when plamod scraper is outdated', function (): void {
    Queue::fake();

    Http::fake([
        'http://plamod_scraper:3001/health' => Http::response(['ok' => true], 200),
    ]);

    $this->postJson('/api/v1/preorders/sync')
        ->assertStatus(422)
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.sync_log_id', null)
        ->assertJsonFragment([
            'error_message' => 'Plamod scraper is running outdated code. Restart the pricing-tool-plamod-scraper container, then retry.',
        ]);

    Queue::assertNothingPushed();
});

it('lists manufacturer filters grouped by decision', function (): void {
    PlamodPreorderManufacturerFilter::query()->create([
        'manufacturer_id' => 1,
        'filter_type' => PlamodPreorderManufacturerFilterType::Series,
        'name' => 'Mobile Suit Gundam',
        'decision' => PlamodPreorderManufacturerFilterDecision::Include,
        'plamod_preorder_count' => 27,
    ]);
    PlamodPreorderManufacturerFilter::query()->create([
        'manufacturer_id' => 1,
        'filter_type' => PlamodPreorderManufacturerFilterType::Series,
        'name' => 'Dragon Ball Z',
        'decision' => PlamodPreorderManufacturerFilterDecision::Undecided,
    ]);

    $this->getJson('/api/v1/preorders/manufacturer-filters')
        ->assertOk()
        ->assertJsonPath('data.counts.include', 1)
        ->assertJsonPath('data.counts.undecided', 1)
        ->assertJsonPath('data.include.0.name', 'Mobile Suit Gundam');
});

it('updates manufacturer filter decisions', function (): void {
    $row = PlamodPreorderManufacturerFilter::query()->create([
        'manufacturer_id' => 1,
        'filter_type' => PlamodPreorderManufacturerFilterType::Series,
        'name' => 'One Piece',
        'decision' => PlamodPreorderManufacturerFilterDecision::Undecided,
    ]);

    $this->putJson('/api/v1/preorders/manufacturer-filters', [
        'updates' => [['id' => $row->id, 'decision' => 'exclude']],
    ])
        ->assertOk()
        ->assertJsonPath('data.exclude.0.name', 'One Piece');

    $row->refresh();
    expect($row->decision)->toBe(PlamodPreorderManufacturerFilterDecision::Exclude);
});

it('queues a background manufacturer filter discover job', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/preorders/manufacturer-filters/discover')
        ->assertOk()
        ->assertJsonStructure(['data' => ['job_id', 'status']])
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(DiscoverPlamodPreorderManufacturerFiltersJob::class);
});

it('returns manufacturer filter discover poll status when completed', function (): void {
    PlamodPreorderManufacturerFilter::query()->create([
        'manufacturer_id' => 1,
        'filter_type' => PlamodPreorderManufacturerFilterType::Series,
        'name' => 'Pokémon',
        'decision' => PlamodPreorderManufacturerFilterDecision::Include,
        'plamod_preorder_count' => 12,
    ]);

    $store = app(PlamodPreorderManufacturerFilterDiscoverStore::class);
    $jobId = $store->create(1);
    $store->complete($jobId, [
        'ok' => true,
        'series_discovered' => 1,
        'category_lines_discovered' => 0,
    ]);

    $this->postJson('/api/v1/preorders/manufacturer-filters/discover', [
        'job_id' => $jobId,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.filters.include.0.name', 'Pokémon');
});

it('updates excluded category settings', function (): void {
    $this->putJson('/api/v1/preorders/settings', [
        'excluded_categories' => ['Shokugan', 'Tools'],
    ])
        ->assertOk()
        ->assertJsonPath('data.excluded_categories', ['Shokugan', 'Tools']);

    $settings = app(PlamodPreorderSettingsService::class)->get();
    expect($settings['excluded_categories'])->toBe(['Shokugan', 'Tools']);
});

it('searches pasted lines and reports not found', function (): void {
    PlamodPreorder::query()->create([
        'sku' => '5058006',
        'barcode' => '4573105580066',
        'product_name' => 'HGUC Qubeley',
        'image_download_status' => 'pending',
    ]);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('searchRetailerPreorders')
        ->once()
        ->with(['missing-kit-name'])
        ->andReturn([
            'ok' => true,
            'results' => ['missing-kit-name' => null],
        ]);
    app()->instance(PlamodScraper::class, $scraper);

    $this->postJson('/api/v1/preorders/search-lines', [
        'lines' => ['5058006', 'missing-kit-name'],
    ])
        ->assertOk()
        ->assertJsonPath('data.matched.0.sku', '5058006')
        ->assertJsonPath('data.not_found.0', 'missing-kit-name')
        ->assertJsonPath('data.plamod_only', []);
});

it('returns snapshot phase without calling live scraper', function (): void {
    PlamodPreorder::query()->create([
        'sku' => '5058006',
        'barcode' => '4573105580066',
        'product_name' => 'HGUC Qubeley',
        'price_preorder' => '10.00',
        'image_download_status' => 'pending',
    ]);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldNotReceive('searchRetailerPreorders');
    app()->instance(PlamodScraper::class, $scraper);

    $this->postJson('/api/v1/preorders/search-lines', [
        'lines' => ['5058006', 'missing-kit-name'],
        'phase' => 'snapshot',
    ])
        ->assertOk()
        ->assertJsonPath('data.matched.0.sku', '5058006')
        ->assertJsonPath('data.pending_live.0', 'missing-kit-name')
        ->assertJsonPath('data.rows.0.sku', '5058006')
        ->assertJsonPath('data.rows.0.unit_selling_price', '15.99');
});

it('queues a background live search job and returns pollable status', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/preorders/search-lines', [
        'lines' => ['RE 1/100 VIGNA-GHINA', 'missing-kit-name'],
        'phase' => 'live_start',
    ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['job_id', 'status']])
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(RunPlamodPreorderLiveSearchJob::class);
});

it('returns live poll status for a queued search job', function (): void {
    $store = app(PlamodPreorderLiveSearchStore::class);
    $jobId = $store->create(['RE 1/100 VIGNA-GHINA']);
    $store->complete($jobId, [[
        'line' => 'RE 1/100 VIGNA-GHINA',
        'sku' => '0225768',
        'product_name' => 'RE 1/100 VIGNA-GHINA',
        'plamod_pdp_url' => 'https://plamod.com/retailer/products/0225768',
    ]], [], [[
        'sku' => '0225768',
        'product_name' => 'RE 1/100 VIGNA-GHINA',
        'not_in_import' => true,
        'unit_selling_price' => '54.99',
    ]]);

    $this->postJson('/api/v1/preorders/search-lines', [
        'phase' => 'live_poll',
        'job_id' => $jobId,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.plamod_only.0.sku', '0225768')
        ->assertJsonPath('data.rows.0.sku', '0225768')
        ->assertJsonPath('data.rows.0.not_in_import', true);
});

it('returns live phase results for pending lines only', function (): void {
    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('searchRetailerPreorders')
        ->once()
        ->with(['RE 1/100 VIGNA-GHINA'])
        ->andReturn([
            'ok' => true,
            'results' => [
                'RE 1/100 VIGNA-GHINA' => [
                    'sku' => '0225768',
                    'product_name' => 'RE 1/100 VIGNA-GHINA',
                    'plamod_pdp_url' => 'https://plamod.com/retailer/products/0225768',
                    'price_preorder' => '36.59',
                    'quantity_preorder' => '2',
                    'po_due_date' => 'Jun 9',
                    'eta_date' => 'Jan 31',
                ],
            ],
        ]);
    app()->instance(PlamodScraper::class, $scraper);

    $this->postJson('/api/v1/preorders/search-lines', [
        'lines' => ['RE 1/100 VIGNA-GHINA'],
        'phase' => 'live',
    ])
        ->assertOk()
        ->assertJsonPath('data.plamod_only.0.sku', '0225768')
        ->assertJsonPath('data.rows.0.sku', '0225768')
        ->assertJsonPath('data.rows.0.not_in_import', true)
        ->assertJsonPath('data.rows.0.unit_selling_price', '54.99')
        ->assertJsonPath('data.rows.0.quantity_preorder', 2)
        ->assertJsonPath('data.not_found', []);
});

it('does not false-match snapshot rows using only generic gundam tokens', function (): void {
    PlamodPreorder::query()->create([
        'sku' => '5061620',
        'product_name' => 'RG 1/144 #25 RX-0 Unicorn Gundam',
        'image_download_status' => 'pending',
    ]);
    PlamodPreorder::query()->create([
        'sku' => '5058006',
        'product_name' => 'HGUC 1/144 #21 RX-78-2 Gundam',
        'image_download_status' => 'pending',
    ]);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldNotReceive('searchRetailerPreorders');
    app()->instance(PlamodScraper::class, $scraper);

    $this->postJson('/api/v1/preorders/search-lines', [
        'lines' => ['HGUC 1/144 #21 RX-78-2 Gundam'],
        'phase' => 'snapshot',
    ])
        ->assertOk()
        ->assertJsonPath('data.matched.0.sku', '5058006')
        ->assertJsonPath('data.pending_live', []);
});

it('falls back to live Plamod search when a line is missing from the import snapshot', function (): void {
    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('searchRetailerPreorders')
        ->once()
        ->with(['RE 1/100 VIGNA-GHINA'])
        ->andReturn([
            'ok' => true,
            'results' => [
                'RE 1/100 VIGNA-GHINA' => [
                    'sku' => '0225768',
                    'product_name' => 'RE 1/100 VIGNA-GHINA',
                    'plamod_pdp_url' => 'https://plamod.com/retailer/products/0225768',
                ],
            ],
        ]);
    app()->instance(PlamodScraper::class, $scraper);

    $this->postJson('/api/v1/preorders/search-lines', [
        'lines' => ['RE 1/100 VIGNA-GHINA'],
    ])
        ->assertOk()
        ->assertJsonPath('data.matched', [])
        ->assertJsonPath('data.plamod_only.0.sku', '0225768')
        ->assertJsonPath('data.plamod_only.0.product_name', 'RE 1/100 VIGNA-GHINA')
        ->assertJsonPath('data.not_found', []);
});
