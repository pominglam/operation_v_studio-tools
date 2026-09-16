<?php

declare(strict_types=1);

use App\DAL\StorePreorders\StorePreorderRepository;
use App\Models\Product;
use App\Services\Shopify\Admin\Write\ShopifyProductPushTagsResolver;
use App\Support\Products\Storefront\ProductStorefrontClassifier;
use App\Support\Products\Storefront\StorefrontTag;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the store-preorder tag when a closed offer later gets a catalog tag push', function (): void {
    $offers = Mockery::mock(StorePreorderRepository::class);
    $offers->shouldReceive('existsForProductId')->once()->with(0)->andReturn(true);

    $product = new Product([
        'sku' => '5084718',
        'description' => 'RG 1/144 GUNDAM GROUND TYPE',
        'main_type' => 'model kit',
        'department' => 'model kits',
        'grade' => 'RG',
        'latest_arrival' => false,
    ]);
    $product->id = 0;

    $resolver = new ShopifyProductPushTagsResolver(
        app(ProductStorefrontClassifier::class),
        $offers,
    );

    $tags = $resolver->tagsForProductSet($product, null, true, true);

    expect($tags)->toContain(StorefrontTag::STORE_PREORDER)
        ->and($tags)->toContain(StorefrontTag::MK_DEPT_MODEL_KITS);
});
