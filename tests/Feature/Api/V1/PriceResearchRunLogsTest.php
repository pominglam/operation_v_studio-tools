<?php

declare(strict_types=1);

use App\Models\PriceResearchRun;
use App\Models\PriceResearchRunLog;
use App\Models\Product;
use Illuminate\Support\Str;

it('returns crawl logs for a run', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'TEST-SKU-1',
        'barcode' => null,
        'description' => 'Test product',
        'type' => null,
        'price' => '10.00',
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    $run = PriceResearchRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'status' => 'running',
        'force' => false,
        'ttl_days' => 14,
        'total_products' => 1,
        'processed_products' => 0,
        'refreshed_products' => 0,
        'skipped_fresh_products' => 0,
        'total_sites' => 7,
        'processed_sites' => 0,
        'quotes_written' => 0,
        'started_at' => now(),
        'finished_at' => null,
        'error_message' => null,
        'product_uuids' => null,
    ]);

    PriceResearchRunLog::query()->create([
        'run_id' => $run->id,
        'run_uuid' => $run->uuid,
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'sku' => $product->sku,
        'site_key' => 'panda_hobby',
        'site_name' => 'Panda Hobby',
        'status' => 'found',
        'product_url' => 'https://example.com/pdp',
        'error_message' => null,
        'started_at' => now()->subSeconds(2),
        'finished_at' => now()->subSecond(),
        'duration_ms' => 1000,
    ]);

    $r = $this->getJson("/api/v1/price-research/runs/{$run->uuid}/logs?per_page=50");

    $r->assertOk();
    $r->assertJsonPath('data.0.sku', 'TEST-SKU-1');
    $r->assertJsonPath('data.0.site_key', 'panda_hobby');
});

it('validates per_page for crawl logs', function (): void {
    $run = PriceResearchRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'status' => 'running',
        'force' => false,
        'ttl_days' => 14,
        'total_products' => 0,
        'processed_products' => 0,
        'refreshed_products' => 0,
        'skipped_fresh_products' => 0,
        'total_sites' => 0,
        'processed_sites' => 0,
        'quotes_written' => 0,
        'started_at' => now(),
        'finished_at' => null,
        'error_message' => null,
        'product_uuids' => null,
    ]);

    $this->getJson("/api/v1/price-research/runs/{$run->uuid}/logs?per_page=9999")
        ->assertUnprocessable();
});

it('returns 404 for crawl logs when run does not exist', function (): void {
    $this->getJson('/api/v1/price-research/runs/'.Str::uuid().'/logs')
        ->assertNotFound();
});


