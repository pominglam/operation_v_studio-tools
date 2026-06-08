<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Product;
use App\Models\ProductDemandDailyRollup;
use App\Models\Shopify\ShopifyOrder;
use App\Models\Shopify\ShopifyOrderLineItem;
use App\Models\Shopify\ShopifySyncState;
use App\Services\Shopify\Admin\Demand\ProductDemandRollupService;
use App\Services\Shopify\Admin\Orders\ShopifyOrderReconcileService;
use App\Services\Shopify\Admin\Orders\ShopifyOrderUpsertService;
use App\Services\Shopify\Admin\ShopifySettingsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

function createDemandTestProduct(string $sku): Product
{
    return Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => $sku,
        'description' => 'Demand test product',
        'type' => 'Others',
        'vendor' => 'Test',
    ]);
}

beforeEach(function (): void {
    Config::set('shopify.store_domain', 'unit.myshopify.com');
    Config::set('shopify.api_version', '2025-10');
});

it('upserts order line items and increments demand rollups', function (): void {
    $product = createDemandTestProduct('SKU-DEM-1');

    $node = [
        'id' => 'gid://shopify/Order/100',
        'legacyResourceId' => '100',
        'name' => '#1001',
        'displayFinancialStatus' => 'PAID',
        'displayFulfillmentStatus' => 'FULFILLED',
        'createdAt' => '2026-05-20T15:00:00Z',
        'updatedAt' => '2026-05-20T16:00:00Z',
        'lineItems' => [
            'nodes' => [[
                'id' => 'gid://shopify/LineItem/1',
                'sku' => 'SKU-DEM-1',
                'quantity' => 3,
                'variant' => ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'SKU-DEM-1'],
            ]],
        ],
    ];

    app(ShopifyOrderUpsertService::class)->upsertFromGraphQlNode($node);

    $order = ShopifyOrder::query()->where('gid', 'gid://shopify/Order/100')->first();
    expect($order)->not->toBeNull()
        ->and($order?->ordered_at_shop_tz?->timezoneName)->toBe('America/Toronto')
        ->and($order?->ordered_at_shop_tz?->format('Y-m-d H:i:s'))->toBe('2026-05-20 11:00:00')
        ->and($order?->graphql_updated_at?->format('Y-m-d H:i:s'))->toBe('2026-05-20 12:00:00');

    expect(ShopifyOrderLineItem::query()->count())->toBe(1);
    expect(ProductDemandDailyRollup::query()->where('product_id', $product->id)->sum('shopify_sold'))->toBe(3);

    $node['lineItems']['nodes'][0]['quantity'] = 5;
    app(ShopifyOrderUpsertService::class)->upsertFromGraphQlNode($node);

    expect(ProductDemandDailyRollup::query()->where('product_id', $product->id)->sum('shopify_sold'))->toBe(5);
});

it('reconciles incrementally using watermark query filter', function (): void {
    $product = createDemandTestProduct('SKU-ORD-1');

    ShopifySyncState::query()->create([
        'sync_key' => ShopifySettingsService::SYNC_KEY_ORDERS,
        'last_success_at' => now()->subHours(13),
    ]);

    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapOrders(
        [[
            'id' => 'gid://shopify/Order/200',
            'legacyResourceId' => '200',
            'name' => '#2001',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'createdAt' => '2026-05-21T10:00:00Z',
            'updatedAt' => '2026-05-21T11:00:00Z',
            'lineItems' => [
                'nodes' => [[
                    'id' => 'gid://shopify/LineItem/2',
                    'sku' => 'SKU-ORD-1',
                    'quantity' => 2,
                    'variant' => ['id' => 'gid://shopify/ProductVariant/2', 'sku' => 'SKU-ORD-1'],
                ]],
            ],
        ]],
        false,
        null,
    ));

    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $log = app(ShopifyOrderReconcileService::class)->reconcileIncremental();

    expect($log->status)->toBe('completed');
    expect(ShopifyOrderLineItem::query()->where('product_id', $product->id)->sum('quantity'))->toBe(2);

    /** @var ShopifySyncState|null $state */
    $state = ShopifySyncState::query()->where('sync_key', ShopifySettingsService::SYNC_KEY_ORDERS)->first();
    expect($state?->last_success_at)->not->toBeNull();
});

it('rebuilds demand rollups from stored line items', function (): void {
    $product = createDemandTestProduct('SKU-RB-1');

    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/1',
        'name' => '#1',
        'display_financial_status' => 'PAID',
    ]);

    ShopifyOrderLineItem::query()->create([
        'order_gid' => 'gid://shopify/Order/1',
        'line_gid' => 'gid://shopify/LineItem/9',
        'sku' => 'SKU-RB-1',
        'product_id' => $product->id,
        'quantity' => 4,
        'sold_on' => '2026-05-10',
    ]);

    $result = app(ProductDemandRollupService::class)->rebuildAll();

    expect($result['shopify_day_rows'])->toBe(1);
    expect(ProductDemandDailyRollup::query()->where('product_id', $product->id)->value('shopify_sold'))->toBe(4);
});

it('does not count cancelled orders in demand rollups', function (): void {
    $product = createDemandTestProduct('SKU-CAN-1');

    $node = [
        'id' => 'gid://shopify/Order/300',
        'legacyResourceId' => '300',
        'name' => '#OVS-1691',
        'displayFinancialStatus' => 'PENDING',
        'displayFulfillmentStatus' => 'UNFULFILLED',
        'cancelledAt' => '2026-04-27T20:34:00Z',
        'createdAt' => '2026-04-27T20:30:00Z',
        'updatedAt' => '2026-04-27T20:34:00Z',
        'lineItems' => [
            'nodes' => [[
                'id' => 'gid://shopify/LineItem/300',
                'sku' => 'SKU-CAN-1',
                'quantity' => 1,
                'variant' => ['id' => 'gid://shopify/ProductVariant/300', 'sku' => 'SKU-CAN-1'],
            ]],
        ],
    ];

    app(ShopifyOrderUpsertService::class)->upsertFromGraphQlNode($node);

    expect(ShopifyOrderLineItem::query()->count())->toBe(1);
    expect(ProductDemandDailyRollup::query()->where('product_id', $product->id)->sum('shopify_sold'))->toBe(0);
});

it('removes demand when a previously counted order is cancelled', function (): void {
    $product = createDemandTestProduct('SKU-CAN-2');

    $node = [
        'id' => 'gid://shopify/Order/301',
        'legacyResourceId' => '301',
        'name' => '#301',
        'displayFinancialStatus' => 'PAID',
        'displayFulfillmentStatus' => 'FULFILLED',
        'createdAt' => '2026-05-01T12:00:00Z',
        'updatedAt' => '2026-05-01T12:00:00Z',
        'lineItems' => [
            'nodes' => [[
                'id' => 'gid://shopify/LineItem/301',
                'sku' => 'SKU-CAN-2',
                'quantity' => 2,
                'variant' => ['id' => 'gid://shopify/ProductVariant/301', 'sku' => 'SKU-CAN-2'],
            ]],
        ],
    ];

    $service = app(ShopifyOrderUpsertService::class);
    $service->upsertFromGraphQlNode($node);

    expect(ProductDemandDailyRollup::query()->where('product_id', $product->id)->sum('shopify_sold'))->toBe(2);

    $node['displayFinancialStatus'] = 'VOIDED';
    $node['cancelledAt'] = '2026-05-02T09:00:00Z';
    $node['updatedAt'] = '2026-05-02T09:00:00Z';
    $service->upsertFromGraphQlNode($node);

    expect(ProductDemandDailyRollup::query()->where('product_id', $product->id)->sum('shopify_sold'))->toBe(0);
});

it('excludes cancelled orders when rebuilding demand rollups', function (): void {
    $product = createDemandTestProduct('SKU-CAN-3');

    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/400',
        'name' => '#400',
        'display_financial_status' => 'PAID',
    ]);
    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/401',
        'name' => '#401',
        'display_financial_status' => 'VOIDED',
        'cancelled_at' => '2026-04-27T20:34:00Z',
    ]);

    ShopifyOrderLineItem::query()->create([
        'order_gid' => 'gid://shopify/Order/400',
        'line_gid' => 'gid://shopify/LineItem/400',
        'sku' => 'SKU-CAN-3',
        'product_id' => $product->id,
        'quantity' => 2,
        'sold_on' => '2026-05-10',
    ]);
    ShopifyOrderLineItem::query()->create([
        'order_gid' => 'gid://shopify/Order/401',
        'line_gid' => 'gid://shopify/LineItem/401',
        'sku' => 'SKU-CAN-3',
        'product_id' => $product->id,
        'quantity' => 4,
        'sold_on' => '2026-05-10',
    ]);

    app(ProductDemandRollupService::class)->rebuildAll();

    expect(ProductDemandDailyRollup::query()->where('product_id', $product->id)->value('shopify_sold'))->toBe(2);
});

it('stores and returns shopify reconcile interval hours', function (): void {
    $service = app(ShopifySettingsService::class);

    expect($service->getOrderReconcileIntervalHours())->toBe(12);

    $service->setOrderReconcileIntervalHours(6);
    expect($service->getOrderReconcileIntervalHours())->toBe(6);
});
