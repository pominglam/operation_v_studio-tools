<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminAccessTokenProviderInterface;
use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Shopify\ShopifyOrder;
use App\Services\Shopify\Admin\Orders\ShopifyOrderUpsertService;
use App\Services\Shopify\Admin\Orders\ShopifyStaffOrdersMonthlyReportService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

beforeEach(function (): void {
    Config::set('shopify.store_domain', 'unit.myshopify.com');
    Config::set('shopify.api_version', '2025-10');

    app()->instance(ShopifyAdminAccessTokenProviderInterface::class, new class implements ShopifyAdminAccessTokenProviderInterface
    {
        public function currentAccessToken(): string
        {
            return 'test-token';
        }
    });
});

it('persists staff attribution fields when upserting an order', function (): void {
    Http::fake([
        '*/admin/api/*/orders/9001.json*' => Http::response(['order' => ['id' => 9001, 'user_id' => 134032556113]], 200),
    ]);

    app(ShopifyOrderUpsertService::class)->upsertFromGraphQlNode([
        'id' => 'gid://shopify/Order/9001',
        'legacyResourceId' => '9001',
        'name' => '#9001',
        'displayFinancialStatus' => 'PAID',
        'displayFulfillmentStatus' => 'FULFILLED',
        'sourceName' => 'pos',
        'channelInformation' => ['channelDefinition' => ['channelName' => 'Main Store (Point of Sale)']],
        'createdAt' => '2026-07-10T16:00:00Z',
        'updatedAt' => '2026-07-10T16:00:00Z',
        'lineItems' => ['nodes' => []],
    ]);

    $order = ShopifyOrder::query()->where('gid', 'gid://shopify/Order/9001')->first();
    expect($order)->not->toBeNull()
        ->and($order?->source_name)->toBe('pos')
        ->and($order?->channel_name)->toBe('Main Store (Point of Sale)')
        ->and($order?->pos_user_id)->toBe(134032556113);
});

it('builds staff orders report from mirrored shopify_orders rows', function (): void {
    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/9101',
        'legacy_numeric_id' => '9101',
        'name' => '#9101',
        'display_financial_status' => 'PAID',
        'source_name' => 'pos',
        'channel_name' => 'Main Store (Point of Sale)',
        'pos_user_id' => 134032556113,
        'subtotal_shop_amount' => '100.00',
        'ordered_at_shop_tz' => '2026-07-02 12:00:00',
        'cancelled_at' => null,
    ]);
    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/9102',
        'legacy_numeric_id' => '9102',
        'name' => '#9102',
        'display_financial_status' => 'PAID',
        'source_name' => 'quick_sale',
        'channel_name' => 'Quick Sale',
        'subtotal_shop_amount' => '50.00',
        'ordered_at_shop_tz' => '2026-07-02 18:00:00',
        'cancelled_at' => null,
    ]);
    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/9103',
        'legacy_numeric_id' => '9103',
        'name' => '#9103',
        'display_financial_status' => 'PAID',
        'source_name' => 'web',
        'channel_name' => 'Online Store',
        'subtotal_shop_amount' => '25.50',
        'ordered_at_shop_tz' => '2026-07-03 10:00:00',
        'cancelled_at' => null,
    ]);
    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/9104',
        'legacy_numeric_id' => '9104',
        'name' => '#9104',
        'display_financial_status' => 'VOIDED',
        'source_name' => 'pos',
        'channel_name' => 'Main Store (Point of Sale)',
        'pos_user_id' => 134032556113,
        'ordered_at_shop_tz' => '2026-07-03 11:00:00',
        'cancelled_at' => '2026-07-03 11:00:00',
    ]);

    $report = app(ShopifyStaffOrdersMonthlyReportService::class)->reportForMonth('2026-07');

    expect($report['data_source'])->toBe('shopify_orders_mirror')
        ->and($report['orders_scanned'])->toBe(3)
        ->and($report['orders_missing_attribution'])->toBe(0)
        ->and($report['totals']['alex_hui'])->toBe(1)
        ->and($report['totals']['quick_sale'])->toBe(1)
        ->and($report['totals']['online_store'])->toBe(1)
        ->and($report['rows'])->toHaveCount(31)
        ->and($report['revenue_totals']['alex_hui'])->toBe('100.00')
        ->and($report['revenue_totals']['quick_sale'])->toBe('50.00')
        ->and($report['revenue_totals']['online_store'])->toBe('25.50')
        ->and($report['revenue_totals']['total'])->toBe('175.50')
        ->and($report['orders_missing_subtotal'])->toBe(0);
});

it('returns staff orders report from the api using mirrored rows', function (): void {
    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/9201',
        'legacy_numeric_id' => '9201',
        'name' => '#9201',
        'display_financial_status' => 'PAID',
        'source_name' => 'pos',
        'channel_name' => 'Main Store (Point of Sale)',
        'pos_user_id' => 134032425041,
        'subtotal_shop_amount' => '42.75',
        'ordered_at_shop_tz' => '2026-07-15 11:00:00',
        'cancelled_at' => null,
    ]);

    $response = $this->getJson('/api/v1/reports/staff-orders?month=2026-07');

    $response->assertOk()
        ->assertJsonPath('data.from_month', '2026-07')
        ->assertJsonPath('data.to_month', '2026-07')
        ->assertJsonPath('data.month', '2026-07')
        ->assertJsonPath('data.data_source', 'shopify_orders_mirror')
        ->assertJsonPath('data.totals.kaz_dizaro', 1)
        ->assertJsonPath('data.revenue_totals.kaz_dizaro', '42.75')
        ->assertJsonPath('data.orders_scanned', 1)
        ->assertJsonCount(31, 'data.rows');
});

it('returns one row per calendar day even when no orders match', function (): void {
    $response = $this->getJson('/api/v1/reports/staff-orders?month=2026-02');

    $response->assertOk()
        ->assertJsonPath('data.orders_scanned', 0)
        ->assertJsonPath('data.totals.total', 0)
        ->assertJsonCount(28, 'data.rows');
});

it('returns staff orders report for a month range', function (): void {
    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/9251',
        'legacy_numeric_id' => '9251',
        'name' => '#9251',
        'display_financial_status' => 'PAID',
        'source_name' => 'pos',
        'channel_name' => 'Main Store (Point of Sale)',
        'pos_user_id' => 134032556113,
        'subtotal_shop_amount' => '10.00',
        'ordered_at_shop_tz' => '2025-12-15 12:00:00',
        'cancelled_at' => null,
    ]);
    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/9252',
        'legacy_numeric_id' => '9252',
        'name' => '#9252',
        'display_financial_status' => 'PAID',
        'source_name' => 'web',
        'channel_name' => 'Online Store',
        'subtotal_shop_amount' => '20.00',
        'ordered_at_shop_tz' => '2026-01-10 12:00:00',
        'cancelled_at' => null,
    ]);

    $response = $this->getJson('/api/v1/reports/staff-orders?from_month=2025-12&to_month=2026-01');

    $response->assertOk()
        ->assertJsonPath('data.from_month', '2025-12')
        ->assertJsonPath('data.to_month', '2026-01')
        ->assertJsonPath('data.month', null)
        ->assertJsonPath('data.orders_scanned', 2)
        ->assertJsonPath('data.revenue_totals.total', '30.00')
        ->assertJsonCount(62, 'data.rows');
});

it('validates month query parameter', function (): void {
    $this->getJson('/api/v1/reports/staff-orders')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from_month']);

    $this->getJson('/api/v1/reports/staff-orders?month=2026-13')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['month']);

    $this->getJson('/api/v1/reports/staff-orders?from_month=2026-07&to_month=2026-06')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to_month']);
});

it('backfills staff attribution columns for mirrored orders in a month', function (): void {
    ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/9301',
        'legacy_numeric_id' => '9301',
        'name' => '#9301',
        'display_financial_status' => 'PAID',
        'ordered_at_shop_tz' => '2026-07-04 12:00:00',
    ]);

    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapOrders([
        [
            'id' => 'gid://shopify/Order/9301',
            'legacyResourceId' => '9301',
            'createdAt' => '2026-07-04T16:00:00Z',
            'sourceName' => 'pos',
            'cancelledAt' => null,
            'displayFinancialStatus' => 'PAID',
            'currentSubtotalPriceSet' => ['shopMoney' => ['amount' => '88.00', 'currencyCode' => 'CAD']],
            'channelInformation' => ['channelDefinition' => ['channelName' => 'Main Store (Point of Sale)']],
        ],
    ], false, null));
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    Http::fake([
        '*/admin/api/*/orders/9301.json*' => Http::response(['order' => ['id' => 9301, 'user_id' => 134032556113]], 200),
    ]);

    $summary = app(ShopifyStaffOrdersMonthlyReportService::class)->backfillAttributionForMonth('2026-07');

    expect($summary['orders_updated'])->toBe(1);

    $order = ShopifyOrder::query()->where('gid', 'gid://shopify/Order/9301')->first();
    expect($order?->source_name)->toBe('pos')
        ->and($order?->pos_user_id)->toBe(134032556113)
        ->and(number_format((float) $order?->subtotal_shop_amount, 2, '.', ''))->toBe('88.00');
});
