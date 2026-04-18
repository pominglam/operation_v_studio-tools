<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Support\Facades\Http;

it('stores competitor description_html when a competitor PDP is fetched and matches', function (): void {
    config()->set('price_research.sites.panda_hobby.base_url', 'https://pandahobby.ca');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090030',
        'sku' => 'ABC123',
        'barcode' => null,
        'description' => 'Test Product ABC123',
        'handle' => null,
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    Http::fake([
        // Search page
        'https://pandahobby.ca/search*' => Http::response(
            '<html><body><a href="/products/test-product-abc123">PDP</a></body></html>',
            200,
        ),
        // PDP page
        'https://pandahobby.ca/products/test-product-abc123' => Http::response(
            '<html><head><meta property="og:title" content="Test Product ABC123"><meta property="og:description" content="This is a long enough competitor description for ABC123 to be captured."></head><body>ABC123</body></html>',
            200,
        ),
    ]);

    /** @var \App\Services\PriceResearch\Providers\PandaHobbyProvider $provider */
    $provider = app(\App\Services\PriceResearch\Providers\PandaHobbyProvider::class);
    $provider->lookup($p);

    $content = \App\Models\ProductExternalContent::query()
        ->where('product_id', $p->id)
        ->where('source', 'panda_hobby')
        ->first();

    expect($content)->not->toBeNull();
    expect($content?->description_html)->toContain('competitor description');
    expect($content?->source_url)->toContain('/products/test-product-abc123');
});
