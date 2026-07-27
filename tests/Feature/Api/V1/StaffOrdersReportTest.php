<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminAccessTokenProviderInterface;
use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Services\Shopify\Admin\Orders\ShopifyStaffOrdersMonthlyReportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

beforeEach(function (): void {
    Config::set('shopify.store_domain', 'unit.myshopify.com');
    Config::set('shopify.api_version', '2025-10');
    Cache::flush();

    app()->instance(ShopifyAdminAccessTokenProviderInterface::class, new class implements ShopifyAdminAccessTokenProviderInterface
    {
        public function currentAccessToken(): string
        {
            return 'test-token';
        }
    });
});

it('aggregates eligible orders into daily staff buckets for a month', function (): void {
    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapOrders([
        [
            'legacyResourceId' => '1001',
            'createdAt' => '2026-07-02T16:00:00Z',
            'sourceName' => 'pos',
            'cancelledAt' => null,
            'displayFinancialStatus' => 'PAID',
            'channelInformation' => ['channelDefinition' => ['channelName' => 'Main Store (Point of Sale)']],
        ],
        [
            'legacyResourceId' => '1002',
            'createdAt' => '2026-07-02T21:00:00Z',
            'sourceName' => 'quick_sale',
            'cancelledAt' => null,
            'displayFinancialStatus' => 'PAID',
            'channelInformation' => ['channelDefinition' => ['channelName' => 'Quick Sale']],
        ],
        [
            'legacyResourceId' => '1003',
            'createdAt' => '2026-07-03T14:00:00Z',
            'sourceName' => 'web',
            'cancelledAt' => null,
            'displayFinancialStatus' => 'PAID',
            'channelInformation' => ['channelDefinition' => ['channelName' => 'Online Store']],
        ],
        [
            'legacyResourceId' => '1004',
            'createdAt' => '2026-07-03T18:00:00Z',
            'sourceName' => 'pos',
            'cancelledAt' => null,
            'displayFinancialStatus' => 'VOIDED',
            'channelInformation' => ['channelDefinition' => ['channelName' => 'Main Store (Point of Sale)']],
        ],
    ], false, null));
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    Http::fake([
        '*/admin/api/*/orders/1001.json*' => Http::response(['order' => ['id' => 1001, 'user_id' => 134032556113]], 200),
    ]);

    $report = app(ShopifyStaffOrdersMonthlyReportService::class)->reportForMonth('2026-07');

    expect($report['month'])->toBe('2026-07')
        ->and($report['timezone'])->toBe('America/Toronto')
        ->and($report['orders_scanned'])->toBe(3)
        ->and($report['totals']['alex_hui'])->toBe(1)
        ->and($report['totals']['quick_sale'])->toBe(1)
        ->and($report['totals']['online_store'])->toBe(1)
        ->and($report['totals']['total'])->toBe(3);

    $julySecond = collect($report['rows'])->firstWhere('date', '2026-07-02');
    expect($julySecond)->not->toBeNull()
        ->and($julySecond['alex_hui'])->toBe(1)
        ->and($julySecond['quick_sale'])->toBe(1)
        ->and($julySecond['total'])->toBe(2);
});

it('returns staff orders report from the api', function (): void {
    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapOrders([
        [
            'legacyResourceId' => '2001',
            'createdAt' => '2026-07-15T15:00:00Z',
            'sourceName' => 'pos',
            'cancelledAt' => null,
            'displayFinancialStatus' => 'PAID',
            'channelInformation' => ['channelDefinition' => ['channelName' => 'Main Store (Point of Sale)']],
        ],
    ], false, null));
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    Http::fake([
        '*/admin/api/*/orders/2001.json*' => Http::response(['order' => ['id' => 2001, 'user_id' => 134032425041]], 200),
    ]);

    $response = $this->getJson('/api/v1/reports/staff-orders?month=2026-07');

    $response->assertOk()
        ->assertJsonPath('data.month', '2026-07')
        ->assertJsonPath('data.totals.kaz_dizaro', 1)
        ->assertJsonPath('data.orders_scanned', 1);
});

it('validates month query parameter', function (): void {
    $this->getJson('/api/v1/reports/staff-orders')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['month']);

    $this->getJson('/api/v1/reports/staff-orders?month=2026-13')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['month']);
});
