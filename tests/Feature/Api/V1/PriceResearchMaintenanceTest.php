<?php

declare(strict_types=1);

use App\DAL\PriceResearch\PriceResearchRunRepository;
use App\DAL\PriceResearch\ProductPriceQuoteRepository;
use App\Models\Product;
use Carbon\CarbonImmutable;

it('can reset a running price research run from maintenance', function (): void {
    /** @var PriceResearchRunRepository $runs */
    $runs = app(PriceResearchRunRepository::class);

    $run = $runs->create(true, 14, 7, 10);
    $run->status = 'running';
    $run->started_at = CarbonImmutable::now()->subMinutes(10);
    $runs->save($run);

    $res = $this->postJson('/api/v1/price-research/runs/reset', ['id' => $run->uuid]);

    $res->assertOk()
        ->assertJsonPath('data.id', $run->uuid)
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error_message', 'Manually reset from Maintenance.');

    $this->assertDatabaseHas('price_research_runs', [
        'uuid' => $run->uuid,
        'status' => 'failed',
    ]);
});

it('returns 409 when there is no queued/running run to reset', function (): void {
    /** @var PriceResearchRunRepository $runs */
    $runs = app(PriceResearchRunRepository::class);

    $run = $runs->create(false, 14, 1, 1);
    $run->status = 'completed';
    $run->finished_at = CarbonImmutable::now();
    $runs->save($run);

    $res = $this->postJson('/api/v1/price-research/runs/reset');

    $res->assertStatus(409)->assertJsonPath('message', 'No queued/running run to reset.');
});

it('can delete a single stored quote from the price research screen', function (): void {
    $product = Product::query()->create([
        'sku' => 'PR-DEL-1',
        'barcode' => '123',
        'description' => 'Delete quote',
        'price' => '10.00',
    ]);

    /** @var ProductPriceQuoteRepository $quotes */
    $quotes = app(ProductPriceQuoteRepository::class);
    $quotes->upsertForProduct($product, [
        'site_key' => 'panda_hobby',
        'site_name' => 'Panda Hobby',
        'status' => 'found',
        'availability' => 'in_stock',
        'currency' => 'CAD',
        'price' => 12.34,
        'original_price' => null,
        'product_url' => 'https://example.test/p/1',
        'error_message' => null,
        'fetched_at' => CarbonImmutable::now(),
    ]);

    $this->assertDatabaseHas('product_price_quotes', [
        'product_id' => $product->id,
        'site_key' => 'panda_hobby',
    ]);

    $res = $this->deleteJson("/api/v1/price-research/products/{$product->uuid}/quotes/panda_hobby");
    $res->assertOk()->assertJson(['deleted' => true]);

    $this->assertDatabaseMissing('product_price_quotes', [
        'product_id' => $product->id,
        'site_key' => 'panda_hobby',
    ]);
});


