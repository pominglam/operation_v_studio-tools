<?php

declare(strict_types=1);

use App\Jobs\Shopify\PullShopifyInventoryToProductsJob;
use App\Jobs\Shopify\RebuildProductDemandRollupsJob;
use App\Jobs\Shopify\ShopifyOrderHistoricalBackfillJob;
use App\Models\Shopify\ShopifySyncLog;
use App\Services\Maintenance\ExternalAccessSettingsService;
use App\Services\Shopify\Admin\ShopifyOpsStatusService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app(ExternalAccessSettingsService::class)->setEnabled(false);
    \Illuminate\Support\Facades\Config::set('shopify.store_domain', 'unit.myshopify.com');
    \Illuminate\Support\Facades\Config::set('shopify.api_version', '2025-10');
    \Illuminate\Support\Facades\Config::set('shopify.oauth_scopes', 'read_orders,read_all_orders,write_orders');

    \App\Models\Shopify\ShopifyOauthInstallation::query()->create([
        'shop_domain' => 'unit.myshopify.com',
        'access_token' => encrypt('test-token'),
        'scopes' => 'read_orders,read_all_orders,write_orders',
    ]);
});

it('persists historical backfill queued status after refresh while job is pending', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/shopify/orders/historical-backfill')->assertOk();

    $this->getJson('/api/v1/shopify/settings')
        ->assertOk()
        ->assertJsonPath('data.tasks.1.key', ShopifyOpsStatusService::SYNC_KEY_ORDERS_HISTORICAL)
        ->assertJsonPath('data.tasks.1.status', 'queued')
        ->assertJsonPath('data.tasks.1.queued', true);

    expect(
        ShopifySyncLog::query()
            ->where('sync_key', ShopifyOpsStatusService::SYNC_KEY_ORDERS_HISTORICAL)
            ->where('status', 'queued')
            ->whereNull('finished_at')
            ->exists(),
    )->toBeTrue();

    Queue::assertPushed(
        ShopifyOrderHistoricalBackfillJob::class,
        fn (ShopifyOrderHistoricalBackfillJob $job): bool => $job->syncLogId > 0,
    );
});

it('persists queued status for demand rebuild and inventory pull after refresh', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/shopify/demand/rebuild-rollups')->assertOk();
    $this->postJson('/api/v1/shopify/inventory/pull-to-products')->assertOk();

    $this->getJson('/api/v1/shopify/settings')
        ->assertOk()
        ->assertJsonPath('data.tasks.2.status', 'queued')
        ->assertJsonPath('data.tasks.3.status', 'queued');

    Queue::assertPushed(RebuildProductDemandRollupsJob::class, fn ($job): bool => $job->syncLogId > 0);
    Queue::assertPushed(PullShopifyInventoryToProductsJob::class, fn ($job): bool => $job->syncLogId > 0);
});

it('reports running status while a maintenance sync log is in progress', function (): void {
    ShopifySyncLog::query()->create([
        'sync_key' => ShopifyOpsStatusService::SYNC_KEY_ORDERS_HISTORICAL,
        'status' => 'running',
        'started_at' => now()->subMinute(),
        'finished_at' => null,
        'counts_json' => [],
    ]);

    $this->getJson('/api/v1/shopify/settings')
        ->assertOk()
        ->assertJsonPath('data.tasks.1.status', 'running');
});

it('reports completed status after historical backfill finishes', function (): void {
    ShopifySyncLog::query()->create([
        'sync_key' => ShopifyOpsStatusService::SYNC_KEY_ORDERS_HISTORICAL,
        'status' => 'completed',
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(2),
        'duration_ms' => 480000,
        'records_fetched' => 42,
        'counts_json' => ['orders' => ['fetched' => 42]],
    ]);

    $this->getJson('/api/v1/shopify/settings')
        ->assertOk()
        ->assertJsonPath('data.tasks.1.status', 'completed')
        ->assertJsonPath('data.tasks.1.records_fetched', 42);
});

it('supersedes stale queued sync logs when dispatching again', function (): void {
    Queue::fake();

    $stale = ShopifySyncLog::query()->create([
        'sync_key' => ShopifyOpsStatusService::SYNC_KEY_ORDERS_HISTORICAL,
        'status' => 'queued',
        'started_at' => now()->subHour(),
        'counts_json' => [],
    ]);

    $this->postJson('/api/v1/shopify/orders/historical-backfill')->assertOk();

    $stale->refresh();
    expect($stale->status)->toBe('failed')
        ->and($stale->error_summary)->toBe('Superseded by a newer queued run.');

    $this->getJson('/api/v1/shopify/settings')
        ->assertOk()
        ->assertJsonPath('data.tasks.1.status', 'queued');
});

it('runs historical backfill job handle with mocked shopify graphql', function (): void {
    \App\Models\Product::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'sku' => 'SKU-HIST-JOB',
        'description' => 'Historical job test',
        'type' => 'Others',
        'vendor' => 'Test',
    ]);

    $fake = new \Tests\Fakes\FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(\Tests\Fakes\FakeShopifyAdminGraphQlClient::wrapOrders([
        [
            'id' => 'gid://shopify/Order/501',
            'legacyResourceId' => '501',
            'name' => '#501',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'createdAt' => '2026-05-20T12:00:00Z',
            'updatedAt' => '2026-05-20T12:00:00Z',
            'lineItems' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [
                    [
                        'id' => 'gid://shopify/LineItem/901',
                        'sku' => 'SKU-HIST-JOB',
                        'quantity' => 2,
                        'variant' => ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'SKU-HIST-JOB'],
                    ],
                ],
            ],
        ],
    ], false, null));

    $this->app->instance(\App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface::class, $fake);

    $log = app(\App\Services\Shopify\Admin\ShopifyMaintenanceRunLogger::class)
        ->queue(ShopifyOpsStatusService::SYNC_KEY_ORDERS_HISTORICAL);

    $job = new ShopifyOrderHistoricalBackfillJob($log->id);
    $job->handle(
        app(\App\Services\Shopify\Admin\Orders\ShopifyOrderReconcileService::class),
        app(\App\Services\Shopify\Admin\ShopifyMaintenanceRunLogger::class),
    );

    $log->refresh();
    expect($log->status)->toBe('completed')
        ->and($log->records_fetched)->toBe(1);

    expect(\App\Models\Shopify\ShopifyOrderLineItem::query()->where('sku', 'SKU-HIST-JOB')->sum('quantity'))->toBe(2);
});

it('reports failed status when the latest sync log failed', function (): void {
    ShopifySyncLog::query()->create([
        'sync_key' => ShopifyOpsStatusService::SYNC_KEY_ORDERS_HISTORICAL,
        'status' => 'failed',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
        'error_summary' => 'Shopify API unavailable',
        'counts_json' => [],
    ]);

    $this->getJson('/api/v1/shopify/settings')
        ->assertOk()
        ->assertJsonPath('data.tasks.1.status', 'failed')
        ->assertJsonPath('data.tasks.1.error_summary', 'Shopify API unavailable');
});
