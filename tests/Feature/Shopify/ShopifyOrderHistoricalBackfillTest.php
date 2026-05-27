<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Shopify\ShopifyOauthInstallation;
use App\Models\Shopify\ShopifyOrder;
use App\Models\Shopify\ShopifySyncLog;
use App\Services\Maintenance\ExternalAccessSettingsService;
use App\Services\Shopify\Admin\Auth\ShopifyOrderAccessScopeGuard;
use App\Services\Shopify\Admin\Orders\ShopifyOrderReconcileService;
use Illuminate\Support\Facades\Config;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

beforeEach(function (): void {
    app(ExternalAccessSettingsService::class)->setEnabled(false);
    Config::set('shopify.store_domain', 'unit.myshopify.com');
    Config::set('shopify.api_version', '2025-10');
    Config::set('shopify.oauth_scopes', 'read_orders,read_all_orders,write_orders');

    ShopifyOauthInstallation::query()->create([
        'shop_domain' => 'unit.myshopify.com',
        'access_token' => encrypt('test-token'),
        'scopes' => 'read_orders,read_all_orders,write_orders',
    ]);
});

function fakeHistoricalOrderNode(int $legacyId, string $createdAt): array
{
    return [
        'id' => "gid://shopify/Order/{$legacyId}",
        'legacyResourceId' => (string) $legacyId,
        'name' => "#{$legacyId}",
        'displayFinancialStatus' => 'PAID',
        'displayFulfillmentStatus' => 'FULFILLED',
        'createdAt' => $createdAt,
        'updatedAt' => $createdAt,
        'lineItems' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [],
        ],
    ];
}

it('requires read_all_orders before queueing historical backfill', function (): void {
    Config::set('shopify.oauth_scopes', 'read_orders,write_orders');
    ShopifyOauthInstallation::query()->delete();

    $this->postJson('/api/v1/shopify/orders/historical-backfill')
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'read_all_orders'));
});

it('paginates historical backfill through all shopify order pages', function (): void {
    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapOrders(
        [fakeHistoricalOrderNode(3, '2026-05-01T12:00:00Z')],
        true,
        'cursor-page-2',
    ));
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapOrders(
        [fakeHistoricalOrderNode(2, '2025-12-20T10:00:00Z')],
        true,
        'cursor-page-3',
    ));
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapOrders(
        [fakeHistoricalOrderNode(1, '2025-11-01T08:00:00Z')],
        false,
        null,
    ));

    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $log = app(ShopifyOrderReconcileService::class)->reconcileHistorical();

    expect($log->status)->toBe('completed')
        ->and($log->records_fetched)->toBe(3)
        ->and($log->counts_json['oldest_order_created_at'] ?? null)->toBe('2025-11-01T08:00:00+00:00')
        ->and(ShopifyOrder::query()->count())->toBe(3)
        ->and(ShopifyOrder::query()->min('ordered_at_shop_tz'))->not->toBeNull();
});

it('reports read_all_orders availability from config and installation scopes', function (): void {
    $guard = app(ShopifyOrderAccessScopeGuard::class);

    expect($guard->hasReadAllOrdersAccess())->toBeTrue();
    expect($guard->configuredScopesIncludeReadAllOrders())->toBeTrue();
    expect($guard->installationScopesIncludeReadAllOrders())->toBeTrue();
});

it('queues historical backfill when read_all_orders is granted', function (): void {
    \Illuminate\Support\Facades\Queue::fake();

    $this->postJson('/api/v1/shopify/orders/historical-backfill')->assertOk();

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Shopify\ShopifyOrderHistoricalBackfillJob::class);
    expect(
        ShopifySyncLog::query()->where('sync_key', 'orders_historical')->where('status', 'queued')->exists(),
    )->toBeTrue();
});
