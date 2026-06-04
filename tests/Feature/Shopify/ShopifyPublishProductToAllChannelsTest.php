<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Product;
use App\Services\Shopify\Admin\Write\ShopifyPublishProductToAllChannelsService;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

it('publishes an active ERP product to all shop publications', function (): void {
    config(['shopify.oauth_scopes' => 'read_publications,write_publications']);

    Cache::forget('shopify.publication_ids');

    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapPublications([
        'gid://shopify/Publication/10',
        'gid://shopify/Publication/20',
    ]));
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapPublishablePublish());
    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000115001',
        'sku' => 'PUB-ALL-1',
        'description' => 'Publish all channels',
        'published_on_shopify' => true,
    ]);

    $service = app(ShopifyPublishProductToAllChannelsService::class);
    $service->publishWhenEligible($product, 'gid://shopify/Product/115001');

    expect($fake)->toBeInstanceOf(FakeShopifyAdminGraphQlClient::class);
});

it('skips channel publish when ERP product is not marked published on shopify', function (): void {
    config(['shopify.oauth_scopes' => 'read_publications,write_publications']);

    $fake = new FakeShopifyAdminGraphQlClient;
    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000115002',
        'sku' => 'PUB-SKIP-1',
        'description' => 'Draft ERP flags',
        'published_on_shopify' => false,
    ]);

    $service = app(ShopifyPublishProductToAllChannelsService::class);
    $service->publishWhenEligible($product, 'gid://shopify/Product/115002');
});
