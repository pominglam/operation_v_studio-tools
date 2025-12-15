<?php

declare(strict_types=1);

use App\DAL\PriceResearch\PriceResearchRunLogRepository;
use App\DAL\PriceResearch\PriceResearchRunRepository;
use App\DAL\PriceResearch\ProductLookupRepository;
use App\DAL\PriceResearch\ProductPriceQuoteRepository;
use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;
use App\Services\PriceResearch\PriceResearchService;
use App\Services\PriceResearch\Providers\CompetitorPriceProvider;
use Carbon\CarbonImmutable;

final class FakeFoundProvider implements CompetitorPriceProvider
{
    public function siteKey(): string
    {
        return 'fake_found';
    }

    public function siteName(): string
    {
        return 'Fake Found';
    }

    public function lookup(Product $product): PriceLookupResult
    {
        return PriceLookupResult::found($this->siteKey(), $this->siteName(), 12.34, 19.99, 'CAD', 'https://example.test/p/1', 'sold_out');
    }
}

final class FakeNotFoundProvider implements CompetitorPriceProvider
{
    public function siteKey(): string
    {
        return 'fake_not_found';
    }

    public function siteName(): string
    {
        return 'Fake Not Found';
    }

    public function lookup(Product $product): PriceLookupResult
    {
        return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
    }
}

function bindFakePriceResearchService(): void
{
    // Ensure controller runs inline in tests for deterministic assertions.
    config()->set('queue.default', 'sync');

    // Allow fake providers to be used as valid site_keys in request validation.
    config()->set('price_research.sites.fake_found', [
        'name' => 'Fake Found',
        'base_url' => 'https://example.test',
    ]);
    config()->set('price_research.sites.fake_not_found', [
        'name' => 'Fake Not Found',
        'base_url' => 'https://example.test',
    ]);

    app()->bind(PriceResearchService::class, function ($app): PriceResearchService {
        return new PriceResearchService(
            $app->make(ProductLookupRepository::class),
            $app->make(ProductPriceQuoteRepository::class),
            $app->make(PriceResearchRunRepository::class),
            $app->make(PriceResearchRunLogRepository::class),
            [new FakeFoundProvider, new FakeNotFoundProvider],
        );
    });
}

it('runs price research and stores found/not_found quotes', function (): void {
    bindFakePriceResearchService();

    $product = Product::query()->create([
        'sku' => 'PR-1',
        'barcode' => '111',
        'description' => 'Price research',
    ]);

    $response = $this->postJson('/api/v1/price-research/run', [
        'ids' => [$product->uuid],
        'force' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('queued', false)
        ->assertJsonPath('data.refreshed', 1)
        ->assertJsonPath('run_id', fn ($v) => is_string($v) && $v !== '');

    $this->assertDatabaseHas('product_price_quotes', [
        'product_id' => $product->id,
        'site_key' => 'fake_found',
        'status' => 'found',
    ]);
    $this->assertDatabaseHas('product_price_quotes', [
        'product_id' => $product->id,
        'site_key' => 'fake_not_found',
        'status' => 'not_found',
    ]);
});

it('can run price research for a single site key (useful when adding a new site)', function (): void {
    bindFakePriceResearchService();

    $product = Product::query()->create([
        'sku' => 'PR-SITE-ONLY',
        'description' => 'Site-only crawl',
    ]);

    $response = $this->postJson('/api/v1/price-research/run', [
        'ids' => [$product->uuid],
        'force' => true,
        'site_keys' => ['fake_found'],
    ]);

    $response->assertOk()->assertJsonPath('queued', false);

    $this->assertDatabaseHas('product_price_quotes', [
        'product_id' => $product->id,
        'site_key' => 'fake_found',
        'status' => 'found',
    ]);
    $this->assertDatabaseMissing('product_price_quotes', [
        'product_id' => $product->id,
        'site_key' => 'fake_not_found',
    ]);

    $this->assertDatabaseHas('price_research_runs', [
        'total_sites' => 1,
    ]);
});

it('rejects unknown site keys for price research runs', function (): void {
    bindFakePriceResearchService();

    $product = Product::query()->create([
        'sku' => 'PR-SITE-BAD',
        'description' => 'Bad site key',
    ]);

    $this->postJson('/api/v1/price-research/run', [
        'ids' => [$product->uuid],
        'force' => true,
        'site_keys' => ['nope'],
    ])->assertStatus(422);
});

it('skips fresh products when not forced', function (): void {
    bindFakePriceResearchService();

    $product = Product::query()->create([
        'sku' => 'PR-2',
        'description' => 'Fresh',
    ]);
    $product->price_researched_at = CarbonImmutable::now()->subDays(2);
    $product->save();

    $response = $this->postJson('/api/v1/price-research/run', [
        'ids' => [$product->uuid],
        'force' => false,
    ]);

    $response->assertOk()->assertJsonPath('data.skipped_fresh', 1);
});

it('lists products with quotes and expired flag', function (): void {
    bindFakePriceResearchService();

    $product = Product::query()->create([
        'sku' => 'PR-3',
        'description' => 'List',
    ]);

    $this->postJson('/api/v1/price-research/run', [
        'ids' => [$product->uuid],
        'force' => true,
    ])->assertOk();

    $response = $this->getJson('/api/v1/price-research/products?per_page=25');

    $response->assertOk()
        ->assertJsonPath('data.0.sku', 'PR-3')
        ->assertJsonPath('data.0.expired', false);
});

it('exposes latest run status', function (): void {
    bindFakePriceResearchService();

    $product = Product::query()->create([
        'sku' => 'PR-4',
        'description' => 'Run status',
    ]);

    $this->postJson('/api/v1/price-research/run', [
        'ids' => [$product->uuid],
        'force' => true,
    ])->assertOk();

    $res = $this->getJson('/api/v1/price-research/runs/latest');
    $res->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.total_products', 1);
});

it('auto-starts a stuck queued run in local dev', function (): void {
    // Simulate local env + async queue with no worker running.
    app()->detectEnvironment(fn () => 'local');
    config()->set('queue.default', 'database');
    config()->set('price_research.local_inline_queue_fallback', true);
    config()->set('price_research.local_queue_stuck_seconds', 0);

    // Use fast fake providers so the inline fallback completes quickly.
    bindFakePriceResearchService();
    // bindFakePriceResearchService forces queue.default=sync for other tests; restore async mode here.
    config()->set('queue.default', 'database');

    $product = Product::query()->create([
        'sku' => 'PR-QUEUE-1',
        'description' => 'Queued',
    ]);

    // Create a queued run directly (avoid needing the DB queue tables in tests).
    /** @var PriceResearchRunRepository $runs */
    $runs = app(PriceResearchRunRepository::class);
    $run = $runs->create(true, 14, 2, 1);

    // Polling status should auto-start inline and return completed.
    $statusRes = $this->getJson("/api/v1/price-research/runs/{$run->uuid}");
    $statusRes->assertOk()->assertJsonPath('data.status', 'completed');
});

it('does not fail if a queued job references a missing run record', function (): void {
    bindFakePriceResearchService();

    // Create a product so the service has work to do.
    Product::query()->create([
        'sku' => 'PR-MISSING-RUN',
        'barcode' => '999',
        'description' => 'Missing run record',
    ]);

    $service = app(PriceResearchService::class);

    // Non-existent run UUID should not throw.
    $out = $service->run(null, true, '00000000-0000-0000-0000-000000000000');

    expect($out['processed'])->toBeGreaterThan(0);
});
