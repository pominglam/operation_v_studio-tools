<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Jobs\PushSelectedProductToShopifyJob;
use App\Models\Product;
use App\Models\ProductSellingPrice;
use App\Models\Shopify\ShopifyLocation;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use Illuminate\Support\Facades\Bus;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

it('previews bulk shopify push for selected products with field options', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory,read_publications,write_publications']);

    $locationGid = 'gid://shopify/Location/9101';
    ShopifyLocation::query()->create([
        'gid' => $locationGid,
        'name' => 'Main warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $existing = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120011',
        'sku' => 'BULK-PUSH-1',
        'description' => 'Existing on Shopify',
        'handle' => 'bulk-push-1',
        'published_on_shopify' => true,
        'available_qty' => 8,
        'hold_qty' => 2,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $existing->id,
        'product_uuid' => $existing->uuid,
        'selling_price' => '24.99',
        'currency' => 'CAD',
    ]);

    $productGid = 'gid://shopify/Product/9102';
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'bulk-push-1',
        'title' => 'Existing',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9103',
        'product_gid' => $productGid,
        'sku' => 'BULK-PUSH-1',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9104',
    ]);

    $create = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120012',
        'sku' => 'BULK-PUSH-NEW',
        'description' => 'New product',
        'available_qty' => 3,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $create->id,
        'product_uuid' => $create->uuid,
        'selling_price' => '9.99',
        'currency' => 'CAD',
    ]);

    $this->postJson('/api/v1/products/shopify-push/preview', [
        'ids' => [$existing->uuid, $create->uuid],
        'push_options' => [
            'info' => true,
            'images' => false,
            'quantities' => true,
            'price' => true,
            'publish_status' => false,
            'sales_channels' => false,
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.push_count', 2)
        ->assertJsonPath('data.create_count', 1)
        ->assertJsonPath('data.update_count', 1)
        ->assertJsonPath('data.products.0.push_action', 'update')
        ->assertJsonPath('data.products.0.shopify_push_qty', 6)
        ->assertJsonPath('data.products.1.push_action', 'create');
});

it('queues bulk shopify push batch for eligible selected products', function (): void {
    Bus::fake();

    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory']);

    $locationGid = 'gid://shopify/Location/9201';
    ShopifyLocation::query()->create([
        'gid' => $locationGid,
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120021',
        'sku' => 'BULK-PUSH-Q',
        'description' => 'Queue me',
        'handle' => 'bulk-push-q',
        'available_qty' => 5,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '14.99',
        'currency' => 'CAD',
    ]);

    $productGid = 'gid://shopify/Product/9202';
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'bulk-push-q',
        'title' => 'Queue me',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9203',
        'product_gid' => $productGid,
        'sku' => 'BULK-PUSH-Q',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9204',
    ]);

    $response = $this->postJson('/api/v1/products/shopify-push/selected', [
        'ids' => [$product->uuid],
        'push_options' => [
            'quantities' => true,
        ],
    ]);

    $response->assertAccepted()
        ->assertJsonPath('queued', 1);

    Bus::assertBatched(function ($batch): bool {
        return $batch->name === 'push_selected_products_shopify'
            && count($batch->jobs) === 1
            && $batch->jobs[0] instanceof PushSelectedProductToShopifyJob;
    });
});

it('rejects bulk shopify push when no fields are selected', function (): void {
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120031',
        'sku' => 'BULK-PUSH-NO-FIELDS',
        'description' => 'No fields',
    ]);

    $this->postJson('/api/v1/products/shopify-push/selected', [
        'ids' => [$product->uuid],
        'push_options' => [
            'info' => false,
            'images' => false,
            'quantities' => false,
            'price' => false,
            'publish_status' => false,
            'sales_channels' => false,
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['push_options']);
});

it('pushes only selected fields via productSet for updates', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory']);

    $locationGid = 'gid://shopify/Location/9301';
    ShopifyLocation::query()->create([
        'gid' => $locationGid,
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120041',
        'sku' => 'BULK-PUSH-PARTIAL',
        'description' => 'Partial push',
        'handle' => 'bulk-push-partial',
        'available_qty' => 10,
        'hold_qty' => 1,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '19.99',
        'currency' => 'CAD',
    ]);

    $productGid = 'gid://shopify/Product/9302';
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'bulk-push-partial',
        'title' => 'Partial',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9303',
        'product_gid' => $productGid,
        'sku' => 'BULK-PUSH-PARTIAL',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9304',
    ]);

    $fake = new class($productGid) implements ShopifyAdminGraphQlClientInterface
    {
        /** @var list<array<string, mixed>> */
        public array $variableCalls = [];

        public function __construct(private readonly string $productGid) {}

        public function query(string $graphql, array $variables = []): array
        {
            $this->variableCalls[] = $variables;

            return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, 'bulk-push-partial');
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $job = new PushSelectedProductToShopifyJob(
        '00000000-0000-0000-0000-000000120099',
        (string) $product->uuid,
        ['quantities' => true],
    );
    app()->call([$job, 'handle']);

    expect($fake->variableCalls)->toHaveCount(1);
    $productSet = $fake->variableCalls[0]['productSet'] ?? [];
    expect($productSet)->not->toHaveKey('title');
    expect($productSet)->toHaveKey('productOptions');
    expect($productSet['variants'][0]['inventoryQuantities'][0]['quantity'] ?? null)->toBe(9);
});
