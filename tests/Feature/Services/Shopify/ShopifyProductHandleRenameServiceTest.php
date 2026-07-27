<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Product;
use App\Models\ProductSellingPrice;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\Products\ProductExportService;
use App\Services\Shopify\Admin\Write\ShopifyProductHandleRenameService;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

it('renames handle in ERP and Shopify and refreshes mirror', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150001',
        'sku' => '5068584',
        'description' => '30MM 1/144 bEXM-6 ROUNDNOVA Ⅱ',
        'handle' => '30mm-1144-bexm-6-roundnova',
        'published_on_shopify' => true,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '19.99',
    ]);

    ShopifyProduct::query()->create([
        'gid' => 'gid://shopify/Product/9501',
        'handle' => '30mm-1144-bexm-6-roundnova',
        'title' => $product->description,
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9502',
        'product_gid' => 'gid://shopify/Product/9501',
        'sku' => '5068584',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9503',
    ]);

    $client = new class implements ShopifyAdminGraphQlClientInterface
    {
        /** @var list<array<string, mixed>> */
        public array $calls = [];

        public function query(string $graphql, array $variables = []): array
        {
            $this->calls[] = ['graphql' => $graphql, 'variables' => $variables];

            if (str_contains($graphql, 'productUpdate')) {
                return [
                    'data' => [
                        'productUpdate' => [
                            'product' => [
                                'id' => 'gid://shopify/Product/9501',
                                'handle' => '30mm-1144-bexm-6-roundnova-ii',
                            ],
                            'userErrors' => [],
                        ],
                    ],
                ];
            }

            if (str_contains($graphql, 'ProductMirrorById')) {
                return FakeShopifyAdminGraphQlClient::wrapProductMirrorNode(
                    'gid://shopify/Product/9501',
                    '30mm-1144-bexm-6-roundnova-ii',
                    '5068584',
                );
            }

            if (str_contains($graphql, 'inventoryItem(id:')) {
                return FakeShopifyAdminGraphQlClient::wrapInventoryItem(
                    'gid://shopify/InventoryItem/9503',
                    ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => []],
                );
            }

            return ['data' => []];
        }
    };

    app()->instance(ShopifyAdminGraphQlClientInterface::class, $client);

    $result = app(ShopifyProductHandleRenameService::class)->rename($product, '30mm-1144-bexm-6-roundnova-ii');

    expect($result['shopify_updated'])->toBeTrue();
    expect($result['old_handle'])->toBe('30mm-1144-bexm-6-roundnova');
    expect($result['new_handle'])->toBe('30mm-1144-bexm-6-roundnova-ii');

    $product->refresh();
    expect($product->handle)->toBe('30mm-1144-bexm-6-roundnova-ii');

    expect(collect($client->calls)->contains(
        static fn (array $call): bool => str_contains($call['graphql'], 'productUpdate')
            && ($call['variables']['input']['handle'] ?? null) === '30mm-1144-bexm-6-roundnova-ii',
    ))->toBeTrue();
});

it('generates distinct roundnova handles and avoids ERP handle collisions', function (): void {
    $service = app(ProductExportService::class);

    $one = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150101',
        'sku' => '5068578',
        'description' => '30MM 1/144 bEXM-6 ROUNDNOVA Ⅰ',
        'handle' => null,
    ]);
    $two = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150102',
        'sku' => '5068584',
        'description' => '30MM 1/144 bEXM-6 ROUNDNOVA Ⅱ',
        'handle' => null,
    ]);

    $used = [];
    $handleOne = $service->shopifyHandleForProduct($one, $used);
    $handleTwo = $service->shopifyHandleForProduct($two, $used);

    expect($handleOne)->toBe('30mm-1144-bexm-6-roundnova-i');
    expect($handleTwo)->toBe('30mm-1144-bexm-6-roundnova-ii');
});
