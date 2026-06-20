<?php

declare(strict_types=1);

use App\Models\Product;

it('includes storefront classification on product list rows', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000000301',
        'sku' => 'MT-03',
        'barcode' => '904',
        'description' => 'Masking tape 3mm',
        'main_type' => 'supplies',
        'type' => 'Others',
        'available_qty' => 1,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&search=MT-03');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'MT-03')
        ->assertJsonPath('data.0.storefront_classification.department', 'tapes')
        ->assertJsonPath('data.0.storefront_classification.storefront_tags.0', 'ts:dept:tapes')
        ->assertJsonPath('data.0.storefront_classification.shopify_tags.2', 'ts:dept:tapes');
});
