<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductSellingPrice;
use App\Models\Shopify\ShopifyLocation;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\Shopify\Admin\Write\ShopifyProductUpsertFromErpService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

/**
 * @return array{
 *   client: object{variableCalls: list<array<string, mixed>>},
 *   productGid: string
 * }
 */
function upsertTestRecordingClient(string $productGid, string $handle, string $sku = 'UPSERT-CREATE'): object
{
    return new class($productGid, $handle, $sku) implements ShopifyAdminGraphQlClientInterface
    {
        /** @var list<array<string, mixed>> */
        public array $variableCalls = [];

        public function __construct(
            private readonly string $productGid,
            private readonly string $handle,
            private readonly string $sku,
        ) {}

        public function query(string $graphql, array $variables = []): array
        {
            $this->variableCalls[] = $variables;

            if (str_contains($graphql, 'productSet')) {
                return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, $this->handle);
            }

            if (str_contains($graphql, 'ProductMirrorById') || str_contains($graphql, 'product(id:')) {
                return FakeShopifyAdminGraphQlClient::wrapProductMirrorNode(
                    $this->productGid,
                    $this->handle,
                    $this->sku,
                );
            }

            if (str_contains($graphql, 'ProductMirrorSearch') || str_contains($graphql, 'products(first: 1, query:')) {
                return FakeShopifyAdminGraphQlClient::wrapProductMirrorSearch([
                    FakeShopifyAdminGraphQlClient::wrapProductMirrorNode(
                        $this->productGid,
                        $this->handle,
                        $this->sku,
                    )['data']['product'],
                ]);
            }

            if (str_contains($graphql, 'inventoryItem(id:')) {
                return FakeShopifyAdminGraphQlClient::wrapInventoryItem(
                    'gid://shopify/InventoryItem/9403',
                    [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'nodes' => [],
                    ],
                );
            }

            return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, $this->handle);
        }
    };
}

function upsertTestMirrorSetup(string $sku, string $handle, string $productGid = 'gid://shopify/Product/9401'): Product
{
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory,read_publications,write_publications']);

    $locationGid = 'gid://shopify/Location/9400';
    ShopifyLocation::query()->create([
        'gid' => $locationGid,
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140001',
        'sku' => $sku,
        'description' => 'RG test kit',
        'handle' => $handle,
        'published_on_shopify' => true,
        'available_qty' => 4,
        'hold_qty' => 1,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '44.99',
        'currency' => 'CAD',
    ]);

    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => $handle,
        'title' => 'RG test kit',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9402',
        'product_gid' => $productGid,
        'sku' => $sku,
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9403',
    ]);

    return $product;
}

function upsertTestFakeImageHttp(): void
{
    Http::fake([
        'https://tunnel.example/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
        'https://auto-started.trycloudflare.com/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
    ]);
}

/**
 * @param  array<int, array{id?: string, status?: string, mediaContentType?: string, mediaErrors?: array<int, array<string, mixed>>}>  $statusNodes
 */
function upsertTestMediaStatusResponse(array $statusNodes): array
{
    return FakeShopifyAdminGraphQlClient::wrapProductMediaStatus($statusNodes);
}

function upsertTestMaybeMirrorRefreshResponse(string $graphql, string $productGid, string $handle, string $sku): ?array
{
    if (str_contains($graphql, 'ProductMirrorById') || str_contains($graphql, 'product(id:')) {
        return FakeShopifyAdminGraphQlClient::wrapProductMirrorNode($productGid, $handle, $sku);
    }

    if (str_contains($graphql, 'inventoryItem(id:')) {
        return FakeShopifyAdminGraphQlClient::wrapInventoryItem(
            'gid://shopify/InventoryItem/9403',
            [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [],
            ],
        );
    }

    return null;
}

it('includes productOptions when updating variants with quantities only', function (): void {
    $product = upsertTestMirrorSetup('UPSERT-QTY-ONLY', 'upsert-qty-only');
    $productGid = 'gid://shopify/Product/9401';

    $fake = upsertTestRecordingClient($productGid, 'upsert-qty-only');
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $result = $service->upsertFromProduct(
        $product,
        null,
        'gid://shopify/Location/9400',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: false,
            images: false,
            quantities: true,
            price: false,
            publishStatus: false,
            salesChannels: false,
        ),
    );

    expect($result['action'])->toBe('updated');
    expect(collect($fake->variableCalls)->contains(fn (array $call): bool => isset($call['productSet'])))->toBeTrue();

    $productSet = collect($fake->variableCalls)->first(fn (array $call): bool => isset($call['productSet']))['productSet'] ?? [];
    expect($productSet)->toHaveKey('productOptions');
    expect($productSet['productOptions'])->toBe([
        [
            'name' => 'Title',
            'values' => [
                ['name' => 'Default Title'],
            ],
        ],
    ]);
    expect($productSet)->not->toHaveKey('title');
    expect($productSet['variants'][0]['inventoryQuantities'][0]['quantity'] ?? null)->toBe(3);
});

it('includes productOptions when updating all push fields on an existing product', function (): void {
    $product = upsertTestMirrorSetup('UPSERT-FULL', 'upsert-full-update', 'gid://shopify/Product/9501');
    $productGid = 'gid://shopify/Product/9501';

    $fake = upsertTestRecordingClient($productGid, 'upsert-full-update');
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $result = $service->upsertFromProduct(
        $product,
        'https://tunnel.example',
        'gid://shopify/Location/9400',
        $usedHandles,
        ShopifyProductPushOptionsDTO::allEnabled(),
    );

    expect($result['action'])->toBe('updated');

    $productSet = collect($fake->variableCalls)->first(
        static fn (array $call): bool => is_array($call['productSet'] ?? null),
    )['productSet'] ?? [];
    expect($productSet)->toHaveKey('productOptions');
    expect($productSet)->toHaveKey('title');
    expect($productSet['title'])->toBe('RG test kit');
    expect($productSet['variants'][0]['price'] ?? null)->toBe('44.99');
});

it('includes productOptions when creating a new product', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory']);

    ShopifyLocation::query()->create([
        'gid' => 'gid://shopify/Location/9600',
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140002',
        'sku' => 'UPSERT-CREATE',
        'description' => 'New kit for create',
        'available_qty' => 2,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '29.99',
        'currency' => 'CAD',
    ]);

    $fake = upsertTestRecordingClient('gid://shopify/Product/9601', 'new-kit-for-create', 'UPSERT-CREATE');
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $result = $service->upsertFromProduct(
        $product,
        'https://tunnel.example',
        'gid://shopify/Location/9600',
        $usedHandles,
        ShopifyProductPushOptionsDTO::allEnabled(),
    );

    expect($result['action'])->toBe('created');

    $mirror = app(\App\Services\Shopify\Admin\Write\ShopifyProductMirrorBySkuResolver::class)->resolve('UPSERT-CREATE');
    expect($mirror)->not->toBeNull()
        ->and($mirror['product_gid'])->toBe('gid://shopify/Product/9601')
        ->and($mirror['shopify_status'])->toBe('ACTIVE');

    $productSet = $fake->variableCalls[0]['productSet'] ?? [];
    expect($productSet)->toHaveKey('productOptions');
    expect($productSet)->toHaveKey('handle');
    expect($productSet['handle'])->toBe('new-kit-for-create');
});

it('links an existing Shopify product by SKU when ERP handle is set but mirror is missing', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory']);

    ShopifyLocation::query()->create([
        'gid' => 'gid://shopify/Location/9700',
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140003',
        'sku' => 'MA-10',
        'description' => 'Stedi weathering MA-10',
        'handle' => 'stedi-ma-10',
        'available_qty' => 5,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '7.99',
        'currency' => 'CAD',
    ]);

    $fake = upsertTestRecordingClient('gid://shopify/Product/9701', 'stedi-ma-10', 'MA-10');
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $result = $service->upsertFromProduct(
        $product,
        null,
        'gid://shopify/Location/9700',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: false,
            images: false,
            quantities: true,
            price: false,
            publishStatus: false,
            salesChannels: false,
        ),
    );

    expect($result['action'])->toBe('updated')
        ->and($result['shopify_gid'])->toBe('gid://shopify/Product/9701');

    $mirror = app(\App\Services\Shopify\Admin\Write\ShopifyProductMirrorBySkuResolver::class)->resolve('MA-10');
    expect($mirror)->not->toBeNull()
        ->and($mirror['product_gid'])->toBe('gid://shopify/Product/9701');
});

it('includes productOptions for bulk push update without images (default dialog matrix)', function (): void {
    $product = upsertTestMirrorSetup('5055586', 'rg-full-armor-unicorn', 'gid://shopify/Product/9801');
    $productGid = 'gid://shopify/Product/9801';

    $fake = upsertTestRecordingClient($productGid, 'rg-full-armor-unicorn');
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $result = $service->upsertFromProduct(
        $product,
        null,
        'gid://shopify/Location/9400',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: true,
            images: false,
            quantities: true,
            price: true,
            publishStatus: true,
            salesChannels: true,
        ),
    );

    expect($result['action'])->toBe('updated');

    $productSet = collect($fake->variableCalls)->first(
        static fn (array $call): bool => is_array($call['productSet'] ?? null),
    )['productSet'] ?? [];
    expect($productSet)->toHaveKey('productOptions');
    expect($productSet)->toHaveKey('title');
    expect($productSet['variants'][0]['price'] ?? null)->toBe('44.99');
    expect($productSet['variants'][0]['inventoryQuantities'][0]['quantity'] ?? null)->toBe(3);
    expect($productSet)->not->toHaveKey('files');
});

it('maps Shopify productSet user error about product options to exception message', function (): void {
    $product = upsertTestMirrorSetup('UPSERT-ERR', 'upsert-err-msg', 'gid://shopify/Product/9701');

    $fake = new class implements ShopifyAdminGraphQlClientInterface
    {
        public function query(string $graphql, array $variables = []): array
        {
            return [
                'data' => [
                    'productSet' => [
                        'product' => null,
                        'userErrors' => [
                            ['message' => 'Product options input is required when updating variants'],
                        ],
                    ],
                ],
            ];
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];

    expect(fn () => $service->upsertFromProduct(
        $product,
        null,
        'gid://shopify/Location/9400',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: false,
            images: false,
            quantities: true,
            price: false,
            publishStatus: false,
            salesChannels: false,
        ),
    ))->toThrow(\App\Exceptions\Shopify\ShopifyGraphQlException::class, 'Product options input is required when updating variants');
});

it('clears existing Shopify media before productSet when pushing images on update', function (): void {
    upsertTestFakeImageHttp();

    $product = upsertTestMirrorSetup('BAS69055', 'sazabi-universal-century-saga', 'gid://shopify/Product/15857197776977');
    $productGid = 'gid://shopify/Product/15857197776977';

    $fake = new class($productGid) implements ShopifyAdminGraphQlClientInterface
    {
        /** @var list<string> */
        public array $operations = [];

        /** @var list<array<string, mixed>> */
        public array $variableCalls = [];

        public function __construct(private readonly string $productGid) {}

        public function query(string $graphql, array $variables = []): array
        {
            $this->variableCalls[] = $variables;
            if (str_contains($graphql, 'ProductMediaIds')) {
                $this->operations[] = 'productMedia';

                return FakeShopifyAdminGraphQlClient::wrapProductMediaIds([
                    'gid://shopify/MediaImage/71307870240849',
                    'gid://shopify/MediaImage/71307870273617',
                ]);
            }
            if (str_contains($graphql, 'productDeleteMedia')) {
                $this->operations[] = 'productDeleteMedia';

                return FakeShopifyAdminGraphQlClient::wrapProductDeleteMedia([
                    'gid://shopify/MediaImage/71307870240849',
                    'gid://shopify/MediaImage/71307870273617',
                ]);
            }
            if (str_contains($graphql, 'productSet')) {
                $this->operations[] = 'productSet';

                return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, 'sazabi-universal-century-saga');
            }
            if (str_contains($graphql, 'ProductMediaStatus')) {
                $this->operations[] = 'productMediaStatus';

                return upsertTestMediaStatusResponse([
                    [
                        'id' => 'gid://shopify/MediaImage/9001',
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

            $mirrorRefresh = upsertTestMaybeMirrorRefreshResponse(
                $graphql,
                $this->productGid,
                'sazabi-universal-century-saga',
                '5065083',
            );
            if ($mirrorRefresh !== null) {
                return $mirrorRefresh;
            }

            throw new RuntimeException('Unexpected GraphQL operation: '.$graphql);
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $storagePath = 'manual_upload/images/'.$product->uuid.'/sazabi-universal-century-saga-01-test.png';
    Storage::disk('local')->put($storagePath, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ));
    ProductExternalAsset::query()->create([
        'product_id' => $product->id,
        'source' => 'manual_upload',
        'kind' => 'image',
        'storage_path' => $storagePath,
        'filename' => 'sazabi-universal-century-saga-01-test.png',
        'mime_type' => 'image/png',
        'shopify_enabled' => true,
        'sort_order' => 1,
    ]);
    $product->load('shopifyImageAssets');

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $result = $service->upsertFromProduct(
        $product,
        'https://tunnel.example',
        'gid://shopify/Location/9400',
        $usedHandles,
        ShopifyProductPushOptionsDTO::allEnabled(),
    );

    expect($result['action'])->toBe('updated');
    expect($fake->operations)->toBe([
        'productMedia',
        'productDeleteMedia',
        'productSet',
        'productMediaStatus',
    ]);

    $productSetCall = collect($fake->variableCalls)->first(
        static fn (array $call): bool => is_array($call['productSet'] ?? null),
    );
    expect($productSetCall)->not->toBeNull();
    expect($productSetCall['productSet']['files'] ?? null)->toHaveCount(1);
});

it('clears existing Shopify media on update with default bulk matrix when ERP has no images', function (): void {
    $product = upsertTestMirrorSetup('5059255', 'action-base-1100-gray', 'gid://shopify/Product/15857197776978');
    $productGid = 'gid://shopify/Product/15857197776978';

    $fake = new class($productGid) implements ShopifyAdminGraphQlClientInterface
    {
        /** @var list<string> */
        public array $operations = [];

        public function __construct(private readonly string $productGid) {}

        public function query(string $graphql, array $variables = []): array
        {
            if (str_contains($graphql, 'ProductMediaIds')) {
                $this->operations[] = 'productMedia';

                return FakeShopifyAdminGraphQlClient::wrapProductMediaIds([
                    'gid://shopify/MediaImage/71307870240850',
                ]);
            }
            if (str_contains($graphql, 'productDeleteMedia')) {
                $this->operations[] = 'productDeleteMedia';

                return FakeShopifyAdminGraphQlClient::wrapProductDeleteMedia([
                    'gid://shopify/MediaImage/71307870240850',
                ]);
            }
            if (str_contains($graphql, 'productSet')) {
                $this->operations[] = 'productSet';

                return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, 'action-base-1100-gray');
            }
            if (str_contains($graphql, 'publications')) {
                return FakeShopifyAdminGraphQlClient::wrapPublications(['gid://shopify/Publication/1']);
            }
            if (str_contains($graphql, 'publishablePublish')) {
                return FakeShopifyAdminGraphQlClient::wrapPublishablePublish();
            }

            $mirrorRefresh = upsertTestMaybeMirrorRefreshResponse(
                $graphql,
                $this->productGid,
                'action-base-1100-gray',
                '5059255',
            );
            if ($mirrorRefresh !== null) {
                return $mirrorRefresh;
            }

            throw new RuntimeException('Unexpected GraphQL operation: '.$graphql);
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $result = $service->upsertFromProduct(
        $product,
        null,
        'gid://shopify/Location/9400',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: true,
            images: false,
            quantities: true,
            price: true,
            publishStatus: true,
            salesChannels: true,
        ),
    );

    expect($result['action'])->toBe('updated');
    expect($fake->operations)->toBe([
        'productMedia',
        'productDeleteMedia',
        'productSet',
    ]);
});

it('does not clear Shopify media on update without images option when ERP still has shopify-enabled assets', function (): void {
    upsertTestFakeImageHttp();

    $product = upsertTestMirrorSetup('UPSERT-KEEP-MEDIA', 'upsert-keep-media', 'gid://shopify/Product/9807');
    $productGid = 'gid://shopify/Product/9807';

    $fake = new class($productGid) implements ShopifyAdminGraphQlClientInterface
    {
        /** @var list<string> */
        public array $operations = [];

        public function __construct(private readonly string $productGid) {}

        public function query(string $graphql, array $variables = []): array
        {
            if (str_contains($graphql, 'ProductMediaIds')) {
                $this->operations[] = 'productMedia';

                return FakeShopifyAdminGraphQlClient::wrapProductMediaIds([]);
            }
            if (str_contains($graphql, 'productSet')) {
                $this->operations[] = 'productSet';

                return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, 'upsert-keep-media');
            }
            if (str_contains($graphql, 'publications')) {
                return FakeShopifyAdminGraphQlClient::wrapPublications(['gid://shopify/Publication/1']);
            }
            if (str_contains($graphql, 'publishablePublish')) {
                return FakeShopifyAdminGraphQlClient::wrapPublishablePublish();
            }

            $mirrorRefresh = upsertTestMaybeMirrorRefreshResponse(
                $graphql,
                $this->productGid,
                'upsert-keep-media',
                'UPSERT-KEEP-MEDIA',
            );
            if ($mirrorRefresh !== null) {
                return $mirrorRefresh;
            }

            throw new RuntimeException('Unexpected GraphQL operation: '.$graphql);
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $storagePath = 'manual_upload/images/'.$product->uuid.'/keep-media.png';
    Storage::disk('local')->put($storagePath, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ));
    ProductExternalAsset::query()->create([
        'product_id' => $product->id,
        'source' => 'manual_upload',
        'kind' => 'image',
        'storage_path' => $storagePath,
        'filename' => 'keep-media.png',
        'mime_type' => 'image/png',
        'shopify_enabled' => true,
        'sort_order' => 1,
    ]);
    $product->load('shopifyImageAssets');

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $service->upsertFromProduct(
        $product,
        null,
        'gid://shopify/Location/9400',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: true,
            images: false,
            quantities: true,
            price: true,
            publishStatus: true,
            salesChannels: true,
        ),
    );

    expect($fake->operations)->toBe(['productSet']);
});

it('clears existing Shopify media when pushing images on update with no ERP images', function (): void {
    $product = upsertTestMirrorSetup('5059255', 'action-base-1100-gray', 'gid://shopify/Product/15857197776978');
    $productGid = 'gid://shopify/Product/15857197776978';

    $fake = new class($productGid) implements ShopifyAdminGraphQlClientInterface
    {
        /** @var list<string> */
        public array $operations = [];

        /** @var list<array<string, mixed>> */
        public array $variableCalls = [];

        public function __construct(private readonly string $productGid) {}

        public function query(string $graphql, array $variables = []): array
        {
            $this->variableCalls[] = $variables;
            if (str_contains($graphql, 'ProductMediaIds')) {
                $this->operations[] = 'productMedia';

                return FakeShopifyAdminGraphQlClient::wrapProductMediaIds([
                    'gid://shopify/MediaImage/71307870240850',
                ]);
            }
            if (str_contains($graphql, 'productDeleteMedia')) {
                $this->operations[] = 'productDeleteMedia';

                return FakeShopifyAdminGraphQlClient::wrapProductDeleteMedia([
                    'gid://shopify/MediaImage/71307870240850',
                ]);
            }
            if (str_contains($graphql, 'productSet')) {
                $this->operations[] = 'productSet';

                return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, 'action-base-1100-gray');
            }
            if (str_contains($graphql, 'publications')) {
                return FakeShopifyAdminGraphQlClient::wrapPublications(['gid://shopify/Publication/1']);
            }
            if (str_contains($graphql, 'publishablePublish')) {
                return FakeShopifyAdminGraphQlClient::wrapPublishablePublish();
            }

            $mirrorRefresh = upsertTestMaybeMirrorRefreshResponse(
                $graphql,
                $this->productGid,
                'action-base-1100-gray',
                '5059255',
            );
            if ($mirrorRefresh !== null) {
                return $mirrorRefresh;
            }

            throw new RuntimeException('Unexpected GraphQL operation: '.$graphql);
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $result = $service->upsertFromProduct(
        $product,
        'https://tunnel.example',
        'gid://shopify/Location/9400',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: true,
            images: true,
            quantities: true,
            price: true,
            publishStatus: true,
            salesChannels: true,
        ),
    );

    expect($result['action'])->toBe('updated');
    expect($fake->operations)->toBe([
        'productMedia',
        'productDeleteMedia',
        'productSet',
    ]);

    $productSetCall = collect($fake->variableCalls)->first(
        static fn (array $call): bool => is_array($call['productSet'] ?? null),
    );
    expect($productSetCall)->not->toBeNull();
    expect($productSetCall['productSet'])->not->toHaveKey('files');
});

it('throws when image push is requested without a running tunnel', function (): void {
    $product = upsertTestMirrorSetup('UPSERT-NO-TUNNEL', 'upsert-no-tunnel');

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];

    expect(fn () => $service->upsertFromProduct(
        $product,
        null,
        'gid://shopify/Location/9400',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: false,
            images: true,
            quantities: false,
            price: false,
            publishStatus: false,
            salesChannels: false,
        ),
    ))->toThrow(\InvalidArgumentException::class, 'Cloudflare tunnel');
});

it('throws when image push is requested but Shopify-enabled files are missing on disk', function (): void {
    $product = upsertTestMirrorSetup('UPSERT-MISSING-FILE', 'upsert-missing-file');

    ProductExternalAsset::query()->create([
        'product_id' => $product->id,
        'source' => 'manual_upload',
        'kind' => 'image',
        'storage_path' => 'manual_upload/images/missing.png',
        'filename' => 'missing.png',
        'mime_type' => 'image/png',
        'shopify_enabled' => true,
        'sort_order' => 1,
    ]);
    $product->load('shopifyImageAssets');

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];

    expect(fn () => $service->upsertFromProduct(
        $product,
        'https://tunnel.example',
        'gid://shopify/Location/9400',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: false,
            images: true,
            quantities: false,
            price: false,
            publishStatus: false,
            salesChannels: false,
        ),
    ))->toThrow(\InvalidArgumentException::class, 'none are readable on disk');
});

it('renames image assets to SEO filenames before pushing images', function (): void {
    upsertTestFakeImageHttp();

    $product = upsertTestMirrorSetup('UPSERT-SEO-NAME', 'upsert-seo-name', 'gid://shopify/Product/9805');
    $productGid = 'gid://shopify/Product/9805';

    $fake = new class($productGid) implements ShopifyAdminGraphQlClientInterface
    {
        /** @var list<array<string, mixed>> */
        public array $variableCalls = [];

        public function __construct(private readonly string $productGid) {}

        public function query(string $graphql, array $variables = []): array
        {
            $this->variableCalls[] = $variables;
            if (str_contains($graphql, 'ProductMediaIds')) {
                return FakeShopifyAdminGraphQlClient::wrapProductMediaIds([]);
            }
            if (str_contains($graphql, 'productSet')) {
                return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, 'upsert-seo-name');
            }
            if (str_contains($graphql, 'ProductMediaStatus')) {
                return upsertTestMediaStatusResponse([
                    [
                        'id' => 'gid://shopify/MediaImage/9002',
                        'status' => 'READY',
                        'mediaContentType' => 'IMAGE',
                        'mediaErrors' => [],
                    ],
                ]);
            }

            $mirrorRefresh = upsertTestMaybeMirrorRefreshResponse(
                $graphql,
                $this->productGid,
                'upsert-seo-name',
                'UPSERT-SEO-NAME',
            );
            if ($mirrorRefresh !== null) {
                return $mirrorRefresh;
            }

            throw new RuntimeException('Unexpected GraphQL operation: '.$graphql);
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $storagePath = 'manual_upload/images/'.$product->uuid.'/IMG_0001.PNG';
    Storage::disk('local')->put($storagePath, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ));
    $asset = ProductExternalAsset::query()->create([
        'product_id' => $product->id,
        'source' => 'manual_upload',
        'kind' => 'image',
        'storage_path' => $storagePath,
        'filename' => 'IMG_0001.PNG',
        'mime_type' => 'image/png',
        'shopify_enabled' => true,
        'sort_order' => 1,
    ]);
    $product->load('shopifyImageAssets');

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $service->upsertFromProduct(
        $product,
        'https://tunnel.example',
        'gid://shopify/Location/9400',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: false,
            images: true,
            quantities: false,
            price: false,
            publishStatus: false,
            salesChannels: false,
        ),
    );

    $asset->refresh();
    expect($asset->filename)->not->toBe('IMG_0001.PNG');
    expect($asset->filename)->toMatch('/^rg-test-kit-\d{2}-\d+\.png$/');

    $productSetCall = collect($fake->variableCalls)->first(
        static fn (array $call): bool => is_array($call['productSet'] ?? null),
    );
    expect($productSetCall)->not->toBeNull();
    expect($productSetCall['productSet']['files'][0]['filename'] ?? null)->toBe($asset->filename);
});

it('throws when Shopify media processing fails after productSet', function (): void {
    upsertTestFakeImageHttp();

    $product = upsertTestMirrorSetup('UPSERT-MEDIA-FAIL', 'upsert-media-fail', 'gid://shopify/Product/9806');
    $productGid = 'gid://shopify/Product/9806';

    $fake = new class($productGid) implements ShopifyAdminGraphQlClientInterface
    {
        public function __construct(private readonly string $productGid) {}

        public function query(string $graphql, array $variables = []): array
        {
            if (str_contains($graphql, 'ProductMediaIds')) {
                return FakeShopifyAdminGraphQlClient::wrapProductMediaIds([]);
            }
            if (str_contains($graphql, 'productSet')) {
                return FakeShopifyAdminGraphQlClient::wrapProductSet($this->productGid, 'upsert-media-fail');
            }
            if (str_contains($graphql, 'ProductMediaStatus')) {
                return upsertTestMediaStatusResponse([
                    [
                        'id' => 'gid://shopify/MediaImage/9003',
                        'status' => 'FAILED',
                        'mediaContentType' => 'IMAGE',
                        'mediaErrors' => [
                            [
                                'code' => 'DOWNLOAD_FAILED',
                                'message' => 'Media download failed',
                                'details' => 'Tunnel closed before Shopify could fetch the image.',
                            ],
                        ],
                    ],
                ]);
            }

            $mirrorRefresh = upsertTestMaybeMirrorRefreshResponse(
                $graphql,
                $this->productGid,
                'upsert-media-fail',
                'UPSERT-MEDIA-FAIL',
            );
            if ($mirrorRefresh !== null) {
                return $mirrorRefresh;
            }

            throw new RuntimeException('Unexpected GraphQL operation: '.$graphql);
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $storagePath = 'manual_upload/images/'.$product->uuid.'/fail-test.png';
    Storage::disk('local')->put($storagePath, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ));
    ProductExternalAsset::query()->create([
        'product_id' => $product->id,
        'source' => 'manual_upload',
        'kind' => 'image',
        'storage_path' => $storagePath,
        'filename' => 'fail-test.png',
        'mime_type' => 'image/png',
        'shopify_enabled' => true,
        'sort_order' => 1,
    ]);
    $product->load('shopifyImageAssets');

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];

    expect(fn () => $service->upsertFromProduct(
        $product,
        'https://tunnel.example',
        'gid://shopify/Location/9400',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: false,
            images: true,
            quantities: false,
            price: false,
            publishStatus: false,
            salesChannels: false,
        ),
    ))->toThrow(\App\Exceptions\Shopify\ShopifyGraphQlException::class, 'Tunnel closed before Shopify could fetch');
});

it('includes storefront ts tags on images-only updates when product is classified', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);

    $productGid = 'gid://shopify/Product/9901';
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140099',
        'sku' => 'MT-02',
        'description' => 'Madworks Masking Tape 2mm',
        'handle' => 'mt-02',
        'main_type' => 'supplies',
        'type' => 'Others',
        'published_on_shopify' => true,
        'available_qty' => 2,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '3.99',
        'currency' => 'CAD',
    ]);
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'mt-02',
        'title' => 'Madworks Masking Tape 2mm',
        'status' => 'ACTIVE',
        'payload_json' => ['tags' => ['supplies', 'Others']],
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9902',
        'product_gid' => $productGid,
        'sku' => 'MT-02',
    ]);

    $fake = upsertTestRecordingClient($productGid, 'mt-02');
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $service = app()->make(ShopifyProductUpsertFromErpService::class);
    $usedHandles = [];
    $service->upsertFromProduct(
        $product,
        null,
        '',
        $usedHandles,
        new ShopifyProductPushOptionsDTO(
            info: false,
            images: false,
            quantities: false,
            price: false,
            publishStatus: false,
            salesChannels: false,
        ),
    );

    $productSetCall = collect($fake->variableCalls)->first(
        static fn (array $call): bool => is_array($call['productSet'] ?? null),
    );

    expect($productSetCall)->not->toBeNull();
    expect($productSetCall['productSet']['tags'] ?? null)->toBe([
        'ts:dept:tapes',
        'ts:tape:masking',
        'ts:tape:width:2',
    ]);
});
