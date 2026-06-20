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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

/**
 * Matches the common Products bulk-push dialog: everything on except Images.
 *
 * @return array<string, bool>
 */
function bulkPushOptionsWithoutImages(): array
{
    return [
        'info' => true,
        'images' => false,
        'quantities' => true,
        'price' => true,
        'publish_status' => true,
        'sales_channels' => true,
    ];
}

it('push job sends productOptions for bulk push update without images (RG-style product)', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory,read_publications,write_publications']);
    Cache::forget('shopify.publication_ids');

    $locationGid = 'gid://shopify/Location/9800';
    ShopifyLocation::query()->create([
        'gid' => $locationGid,
        'name' => 'Main Store',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $productGid = 'gid://shopify/Product/9801';
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150001',
        'sku' => '5055586',
        'description' => 'RG 1/144 #30 RX-0 FULL ARMOR UNICORN GUNDAM',
        'handle' => 'rg-full-armor-unicorn',
        'published_on_shopify' => true,
        'available_qty' => 6,
        'hold_qty' => 0,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '89.99',
        'currency' => 'CAD',
    ]);

    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'rg-full-armor-unicorn',
        'title' => 'RG Full Armor Unicorn',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9802',
        'product_gid' => $productGid,
        'sku' => '5055586',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9803',
    ]);

    $fake = new class($productGid) implements ShopifyAdminGraphQlClientInterface
    {
        /** @var list<array<string, mixed>> */
        public array $variableCalls = [];

        /** @var list<string> */
        public array $operations = [];

        public function __construct(private readonly string $productGid) {}

        public function query(string $graphql, array $variables = []): array
        {
            $this->variableCalls[] = $variables;
            if (str_contains($graphql, 'productSet')) {
                $this->operations[] = 'productSet';

                return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, 'rg-full-armor-unicorn');
            }
            if (str_contains($graphql, 'publications')) {
                $this->operations[] = 'publications';

                return FakeShopifyAdminGraphQlClient::wrapPublications([
                    'gid://shopify/Publication/1',
                ]);
            }
            if (str_contains($graphql, 'publishablePublish')) {
                $this->operations[] = 'publishablePublish';

                return FakeShopifyAdminGraphQlClient::wrapPublishablePublish();
            }

            throw new RuntimeException('Unexpected GraphQL operation in push job test.');
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $job = new PushSelectedProductToShopifyJob(
        '00000000-0000-0000-0000-000000150099',
        (string) $product->uuid,
        bulkPushOptionsWithoutImages(),
    );
    expect($job->queue)->toBe(PushSelectedProductToShopifyJob::QUEUE);
    app()->call([$job, 'handle']);

    expect($fake->operations)->toContain('productSet');

    $productSetCall = collect($fake->variableCalls)->first(
        static fn (array $call): bool => is_array($call['productSet'] ?? null),
    );
    expect($productSetCall)->not->toBeNull();

    $productSet = $productSetCall['productSet'];
    expect($productSet)->toHaveKey('productOptions');
    expect($productSet['productOptions'])->toBe([
        [
            'name' => 'Title',
            'values' => [
                ['name' => 'Default Title'],
            ],
        ],
    ]);
    expect($productSet['id'] ?? null)->toBe($productGid);
    expect($productSet['variants'][0]['id'] ?? null)->toBe('gid://shopify/ProductVariant/9802');
    expect($productSet)->toHaveKey('title');
    expect($productSet['variants'][0]['price'] ?? null)->toBe('89.99');
    expect($productSet['variants'][0]['inventoryQuantities'][0]['quantity'] ?? null)->toBe(6);
    expect($productSet)->not->toHaveKey('files');
});

it('push job acquires image tunnel lease and sends files when images option is enabled', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory,read_publications,write_publications']);
    Cache::forget('shopify.publication_ids');
    Http::fake([
        'https://auto-started.trycloudflare.com/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $locationGid = 'gid://shopify/Location/9810';
    ShopifyLocation::query()->create([
        'gid' => $locationGid,
        'name' => 'Main Store',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $productGid = 'gid://shopify/Product/9811';
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150010',
        'sku' => 'PUSH-IMAGES-1',
        'description' => 'Push images lease test kit',
        'handle' => 'push-images-lease-test',
        'published_on_shopify' => true,
        'available_qty' => 2,
        'hold_qty' => 0,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '19.99',
        'currency' => 'CAD',
    ]);
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'push-images-lease-test',
        'title' => 'Push images lease test kit',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9812',
        'product_gid' => $productGid,
        'sku' => 'PUSH-IMAGES-1',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9813',
    ]);

    $storagePath = 'manual_upload/images/'.$product->uuid.'/lease-test.png';
    Illuminate\Support\Facades\Storage::disk('local')->put($storagePath, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ));
    App\Models\ProductExternalAsset::query()->create([
        'product_id' => $product->id,
        'source' => 'manual_upload',
        'kind' => 'image',
        'storage_path' => $storagePath,
        'filename' => 'lease-test.png',
        'mime_type' => 'image/png',
        'shopify_enabled' => true,
        'sort_order' => 1,
    ]);

    $fake = new class($productGid) implements ShopifyAdminGraphQlClientInterface
    {
        /** @var list<array<string, mixed>> */
        public array $variableCalls = [];

        /** @var list<string> */
        public array $operations = [];

        public function __construct(private readonly string $productGid) {}

        public function query(string $graphql, array $variables = []): array
        {
            $this->variableCalls[] = $variables;
            if (str_contains($graphql, 'ProductMediaIds')) {
                $this->operations[] = 'productMediaIds';

                return FakeShopifyAdminGraphQlClient::wrapProductMediaIds([]);
            }
            if (str_contains($graphql, 'productSet')) {
                $this->operations[] = 'productSet';

                return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, 'push-images-lease-test');
            }
            if (str_contains($graphql, 'ProductMediaStatus')) {
                $this->operations[] = 'productMediaStatus';

                return FakeShopifyAdminGraphQlClient::wrapProductMediaStatus([
                    [
                        'id' => 'gid://shopify/MediaImage/9100',
                        'status' => 'READY',
                        'mediaContentType' => 'IMAGE',
                        'mediaErrors' => [],
                    ],
                ]);
            }
            if (str_contains($graphql, 'publications')) {
                return FakeShopifyAdminGraphQlClient::wrapPublications(['gid://shopify/Publication/1']);
            }
            if (str_contains($graphql, 'publishablePublish')) {
                return FakeShopifyAdminGraphQlClient::wrapPublishablePublish();
            }

            throw new RuntimeException('Unexpected GraphQL operation in push job image test.');
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    app()->instance(App\Services\Shopify\CloudflaredTunnel::class, new class implements App\Services\Shopify\CloudflaredTunnel
    {
        private bool $running = false;

        /** @var list<string> */
        public array $events = [];

        public function status(): array
        {
            return [
                'running' => $this->running,
                'tunnel_url' => $this->running ? 'https://auto-started.trycloudflare.com' : null,
                'container_id' => 'cid',
                'error' => null,
            ];
        }

        public function start(): array
        {
            $this->running = true;
            $this->events[] = 'start';

            return [
                'ok' => true,
                'tunnel_url' => 'https://auto-started.trycloudflare.com',
                'error' => null,
            ];
        }

        public function stop(): array
        {
            $this->running = false;
            $this->events[] = 'stop';

            return ['ok' => true, 'error' => null];
        }
    });

    $tunnel = app(App\Services\Shopify\CloudflaredTunnel::class);

    $job = new PushSelectedProductToShopifyJob(
        '00000000-0000-0000-0000-000000150110',
        (string) $product->uuid,
        [
            'info' => false,
            'images' => true,
            'quantities' => false,
            'price' => false,
            'publish_status' => false,
            'sales_channels' => false,
        ],
    );
    app()->call([$job, 'handle']);

    $productSetCall = collect($fake->variableCalls)->first(
        static fn (array $call): bool => is_array($call['productSet'] ?? null),
    );
    expect($productSetCall)->not->toBeNull();
    expect($productSetCall['productSet']['files'] ?? null)->toHaveCount(1);
    expect($productSetCall['productSet']['files'][0]['originalSource'] ?? '')
        ->toStartWith('https://auto-started.trycloudflare.com/shopify-images/');
    expect($fake->operations)->toBe([
        'productMediaIds',
        'productSet',
        'productMediaStatus',
    ]);
    expect($tunnel->events)->toBe(['start', 'stop']);
});

it('bulk push selected API queues job with snake_case push_options preserved', function (): void {
    Bus::fake();

    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory']);

    $locationGid = 'gid://shopify/Location/9900';
    ShopifyLocation::query()->create([
        'gid' => $locationGid,
        'name' => 'Main Store',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $productGid = 'gid://shopify/Product/9901';
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000150002',
        'sku' => 'BULK-PUSH-OPTS',
        'description' => 'Bulk push options test',
        'handle' => 'bulk-push-opts',
        'published_on_shopify' => true,
        'available_qty' => 2,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '12.99',
        'currency' => 'CAD',
    ]);
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'bulk-push-opts',
        'title' => 'Bulk push options test',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9902',
        'product_gid' => $productGid,
        'sku' => 'BULK-PUSH-OPTS',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9903',
    ]);

    $response = test()->postJson('/api/v1/products/shopify-push/selected', [
        'ids' => [$product->uuid],
        'push_options' => bulkPushOptionsWithoutImages(),
    ]);

    $response->assertAccepted();

    Bus::assertBatched(function ($batch): bool {
        $job = $batch->jobs[0] ?? null;

        return $batch->name === 'push_selected_products_shopify'
            && $job instanceof PushSelectedProductToShopifyJob
            && $job->pushOptions === bulkPushOptionsWithoutImages();
    });
});
