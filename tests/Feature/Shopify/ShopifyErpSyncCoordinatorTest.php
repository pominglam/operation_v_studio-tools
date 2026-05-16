<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Shopify\ShopifyInventoryLevel;
use App\Models\Shopify\ShopifyLocation;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\Shopify\Admin\Sync\ShopifyErpSyncCoordinator;
use Illuminate\Support\Facades\Config;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

beforeEach(function (): void {
    Config::set('shopify.store_domain', 'unit.myshopify.com');
    Config::set('shopify.api_version', '2025-10');
});

it('runs locations sync runner idempotently from GraphQL pages', function (): void {
    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapLocations(
        [[
            'id' => 'gid://shopify/Location/1',
            'legacyResourceId' => '1',
            'name' => 'Shop',
            'isActive' => true,
            'fulfillsOnlineOrders' => true,
            'updatedAt' => '2025-01-01T00:00:00Z',
        ]],
        false,
        null,
    ));
    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $coordinator = $this->app->make(ShopifyErpSyncCoordinator::class);
    $coordinator->sync('locations');

    expect(ShopifyLocation::query()->count())->toBe(1);

    $fake2 = new FakeShopifyAdminGraphQlClient;
    $fake2->queueResponse(FakeShopifyAdminGraphQlClient::wrapLocations(
        [[
            'id' => 'gid://shopify/Location/1',
            'legacyResourceId' => '1',
            'name' => 'Shop Updated',
            'isActive' => true,
            'fulfillsOnlineOrders' => false,
            'updatedAt' => '2025-02-02T00:00:00Z',
        ]],
        false,
        null,
    ));
    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake2);
    $coordinator2 = $this->app->make(ShopifyErpSyncCoordinator::class);
    $coordinator2->sync('locations');

    expect(ShopifyLocation::query()->count())->toBe(1);
    /** @var \App\Models\Shopify\ShopifyLocation|null $loc */
    $loc = ShopifyLocation::query()->where('gid', 'gid://shopify/Location/1')->first();
    expect($loc?->name)->toBe('Shop Updated');
});

it('syncs product catalog then inventory levels for stored inventory items', function (): void {
    $catalog = new FakeShopifyAdminGraphQlClient;
    $catalog->queueResponse(FakeShopifyAdminGraphQlClient::wrapProducts(
        [[
            'id' => 'gid://shopify/Product/10',
            'legacyResourceId' => '10',
            'handle' => 'deck',
            'title' => 'Deck',
            'status' => 'ACTIVE',
            'vendor' => 'V',
            'updatedAt' => '2025-01-01T00:00:00Z',
            'variants' => [
                'nodes' => [[
                    'id' => 'gid://shopify/ProductVariant/900',
                    'legacyResourceId' => '900',
                    'sku' => 'SKU-1',
                    'barcode' => null,
                    'inventoryQuantity' => 3,
                    'updatedAt' => '2025-01-01T00:00:00Z',
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/700',
                        'legacyResourceId' => '700',
                        'sku' => 'SKU-1',
                        'tracked' => true,
                        'requiresShipping' => true,
                        'updatedAt' => '2025-01-01T00:00:00Z',
                    ],
                ]],
            ],
        ]],
        false,
        null,
    ));

    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $catalog);
    $this->app->make(ShopifyErpSyncCoordinator::class)->sync('products');

    expect(ShopifyProduct::query()->count())->toBe(1);
    expect(ShopifyProductVariant::query()->count())->toBe(1);

    $levels = new FakeShopifyAdminGraphQlClient;
    $levels->queueResponse(FakeShopifyAdminGraphQlClient::wrapInventoryItem(
        'gid://shopify/InventoryItem/700',
        [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [[
                'id' => 'gid://shopify/InventoryLevel/1',
                'quantities' => [['name' => 'available', 'quantity' => 3]],
                'location' => ['id' => 'gid://shopify/Location/1'],
                'updatedAt' => '2025-01-01T00:00:00Z',
            ]],
        ],
    ));

    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $levels);
    $this->app->make(ShopifyErpSyncCoordinator::class)->sync('inventory_levels');

    expect(ShopifyInventoryLevel::query()->count())->toBe(1);
});
