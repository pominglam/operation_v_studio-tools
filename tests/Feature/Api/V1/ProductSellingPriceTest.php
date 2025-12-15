<?php

declare(strict_types=1);

use App\Models\Product;

it('can upsert a selling price for a product and returns it on price research products', function (): void {
    // Create product
    $product = Product::query()->create([
        'sku' => 'SELL-1',
        'description' => 'Selling price test',
        'price' => '10.00',
    ]);

    // Save selling price
    $this->putJson("/api/v1/products/{$product->uuid}/selling-price", [
        'selling_price' => 24.99,
    ])->assertOk()->assertJsonPath('data.selling_price', '24.99');

    // Verify it shows up on price research products payload
    $res = $this->getJson('/api/v1/price-research/products?per_page=25&search=SELL-1');
    $res->assertOk();
    $res->assertJsonPath('data.0.selling_price', '24.99');
});

