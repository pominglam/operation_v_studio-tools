<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductPriceQuote;
use App\Models\PriceResearchQuoteReport;
use Illuminate\Support\Str;

it('creates a quote report with a snapshot of the latest quote', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'TEST-SKU-REPORT-1',
        'barcode' => null,
        'description' => 'Test product',
        'type' => null,
        'price' => '10.00',
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    ProductPriceQuote::query()->create([
        'product_id' => $product->id,
        'site_key' => 'panda_hobby',
        'site_name' => 'Panda Hobby',
        'status' => 'found',
        'availability' => 'in_stock',
        'currency' => 'CAD',
        'price' => 12.34,
        'original_price' => 19.99,
        'product_url' => 'https://example.com/pdp',
        'error_message' => null,
        'fetched_at' => now(),
    ]);

    $res = $this->postJson('/api/v1/price-research/reports', [
        'product_id' => $product->uuid,
        'site_key' => 'panda_hobby',
        'note' => 'Looks wrong (test)',
        'run_id' => (string) Str::uuid(),
    ]);

    $res->assertCreated();
    $res->assertJsonPath('data.sku', 'TEST-SKU-REPORT-1');
    $res->assertJsonPath('data.site_key', 'panda_hobby');
    $res->assertJsonPath('data.price', '12.34');
    $res->assertJsonPath('data.note', 'Looks wrong (test)');

    expect(PriceResearchQuoteReport::query()->count())->toBe(1);
});

it('returns 404 when reporting a product that does not exist', function (): void {
    $this->postJson('/api/v1/price-research/reports', [
        'product_id' => (string) Str::uuid(),
        'site_key' => 'panda_hobby',
    ])->assertNotFound();
});

it('returns 404 when reporting a quote that does not exist for the product/site', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'TEST-SKU-REPORT-2',
        'barcode' => null,
        'description' => 'Test product 2',
        'type' => null,
        'price' => '10.00',
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    $this->postJson('/api/v1/price-research/reports', [
        'product_id' => $product->uuid,
        'site_key' => 'panda_hobby',
    ])->assertNotFound();
});

it('lists quote reports', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'TEST-SKU-REPORT-3',
        'barcode' => null,
        'description' => 'Test product 3',
        'type' => null,
        'price' => '10.00',
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    PriceResearchQuoteReport::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'sku' => $product->sku,
        'site_key' => 'panda_hobby',
        'site_name' => 'Panda Hobby',
        'status' => 'found',
        'availability' => 'in_stock',
        'currency' => 'CAD',
        'price' => '12.34',
        'original_price' => '19.99',
        'product_url' => 'https://example.com/pdp',
        'error_message' => null,
        'fetched_at' => now(),
        'run_uuid' => null,
        'note' => 'Test note',
    ]);

    $res = $this->getJson('/api/v1/price-research/reports?per_page=50');
    $res->assertOk();
    $res->assertJsonPath('data.0.sku', 'TEST-SKU-REPORT-3');
    $res->assertJsonPath('data.0.note', 'Test note');
});


