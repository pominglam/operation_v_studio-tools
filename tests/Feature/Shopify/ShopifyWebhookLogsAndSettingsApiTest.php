<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductDemandDailyRollup;
use App\Models\Shopify\ShopifyWebhookLog;
use App\Services\Maintenance\ExternalAccessSettingsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app(ExternalAccessSettingsService::class)->setEnabled(false);
    \Illuminate\Support\Facades\Config::set('shopify.oauth_scopes', 'read_orders,read_all_orders,write_orders');

    \App\Models\Shopify\ShopifyOauthInstallation::query()->create([
        'shop_domain' => 'unit.myshopify.com',
        'access_token' => encrypt('test-token'),
        'scopes' => 'read_orders,read_all_orders,write_orders',
    ]);
});

it('lists shopify webhook logs with pagination', function (): void {
    ShopifyWebhookLog::query()->create([
        'shop_domain' => 'unit.myshopify.com',
        'topic' => 'orders/create',
        'verification_ok' => true,
        'processing_status' => 'dispatched',
        'payload_json' => ['id' => 1],
    ]);

    $response = $this->getJson('/api/v1/shopify/webhook-logs?per_page=10');

    $response->assertOk()
        ->assertJsonPath('data.0.topic', 'orders/create')
        ->assertJsonPath('data.0.payload_json', null);
});

it('shows a single webhook log with payload', function (): void {
    $log = ShopifyWebhookLog::query()->create([
        'shop_domain' => 'unit.myshopify.com',
        'topic' => 'orders/updated',
        'verification_ok' => true,
        'processing_status' => 'processed',
        'payload_json' => ['admin_graphql_api_id' => 'gid://shopify/Order/9'],
    ]);

    $response = $this->getJson("/api/v1/shopify/webhook-logs/{$log->id}");

    $response->assertOk()
        ->assertJsonPath('data.payload_json.admin_graphql_api_id', 'gid://shopify/Order/9');
});

it('updates shopify settings reconcile interval', function (): void {
    $response = $this->putJson('/api/v1/shopify/settings', [
        'order_reconcile_interval_hours' => 24,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.order_reconcile_interval_hours', 24);
});

it('returns shopify ops status with task snapshots', function (): void {
    \App\Models\Shopify\ShopifySyncLog::query()->create([
        'sync_key' => \App\Services\Shopify\Admin\ShopifyOpsStatusService::SYNC_KEY_DEMAND_REBUILD,
        'status' => 'completed',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
        'duration_ms' => 60000,
        'counts_json' => ['shopify_day_rows' => 3],
    ]);

    $response = $this->getJson('/api/v1/shopify/settings');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'order_reconcile_interval_hours',
                'orders_last_success_at',
                'next_order_reconcile_due_at',
                'last_webhook_received_at',
                'tasks' => [
                    ['key', 'label', 'status', 'queued', 'last_started_at', 'last_finished_at'],
                ],
            ],
        ])
        ->assertJsonPath('data.tasks.2.key', 'demand_rebuild_rollups')
        ->assertJsonPath('data.tasks.2.status', 'completed');
});

it('rejects invalid shopify settings interval', function (): void {
    $response = $this->putJson('/api/v1/shopify/settings', [
        'order_reconcile_interval_hours' => 0,
    ]);

    $response->assertStatus(422);
});

it('returns product demand detail with weekly rollups and order links', function (): void {
    Config::set('shopify.store_domain', 'unit.myshopify.com');

    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'SKU-DEM-DETAIL',
        'description' => 'Demand detail product',
        'type' => 'Others',
        'vendor' => 'Test',
    ]);

    ProductDemandDailyRollup::query()->create([
        'product_id' => $product->id,
        'sold_on' => now()->subDays(3)->toDateString(),
        'shopify_sold' => 5,
        'assumed_sold' => 0,
    ]);
    ProductDemandDailyRollup::query()->create([
        'product_id' => $product->id,
        'sold_on' => now()->subDays(2)->toDateString(),
        'shopify_sold' => 3,
        'assumed_sold' => 0,
    ]);

    \App\Models\Shopify\ShopifyOrder::query()->create([
        'gid' => 'gid://shopify/Order/9001',
        'legacy_numeric_id' => '9001',
        'name' => '#9001',
    ]);

    \App\Models\Shopify\ShopifyOrderLineItem::query()->create([
        'order_gid' => 'gid://shopify/Order/9001',
        'line_gid' => 'gid://shopify/LineItem/9001',
        'sku' => 'SKU-DEM-DETAIL',
        'product_id' => $product->id,
        'quantity' => 2,
        'sold_on' => now()->subDays(2)->toDateString(),
    ]);

    $response = $this->getJson("/api/v1/products/{$product->uuid}/demand");

    $response->assertOk()
        ->assertJsonPath('data.sku', 'SKU-DEM-DETAIL')
        ->assertJsonPath('data.shopify_sold_4w', 8)
        ->assertJsonPath('data.sold_4w', 8)
        ->assertJsonPath('data.detail_window_days', 365)
        ->assertJsonCount(53, 'data.weekly_rollups')
        ->assertJsonPath('data.recent_shopify_lines.0.order_name', '#9001')
        ->assertJsonPath('data.recent_shopify_lines.0.order_admin_url', 'https://unit.myshopify.com/admin/orders/9001')
        ->assertJsonPath('data.recent_shopify_lines_meta.per_page', 10)
        ->assertJsonPath('data.recent_shopify_lines_meta.total', 1);

    expect(collect($response->json('data.weekly_rollups'))->sum('shopify_sold'))->toBe(8);
});

it('paginates recent shopify lines on product demand detail', function (): void {
    Config::set('shopify.store_domain', 'unit.myshopify.com');

    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'SKU-DEM-PAGE',
        'description' => 'Demand pagination product',
        'type' => 'Others',
        'vendor' => 'Test',
    ]);

    for ($i = 1; $i <= 12; $i++) {
        \App\Models\Shopify\ShopifyOrder::query()->create([
            'gid' => "gid://shopify/Order/{$i}",
            'legacy_numeric_id' => (string) $i,
            'name' => "#{$i}",
        ]);

        \App\Models\Shopify\ShopifyOrderLineItem::query()->create([
            'order_gid' => "gid://shopify/Order/{$i}",
            'line_gid' => "gid://shopify/LineItem/{$i}",
            'sku' => 'SKU-DEM-PAGE',
            'product_id' => $product->id,
            'quantity' => 1,
            'sold_on' => now()->subDays($i)->toDateString(),
        ]);
    }

    $page1 = $this->getJson("/api/v1/products/{$product->uuid}/demand?lines_page=1&lines_per_page=10");
    $page1->assertOk()
        ->assertJsonPath('data.recent_shopify_lines_meta.current_page', 1)
        ->assertJsonPath('data.recent_shopify_lines_meta.last_page', 2)
        ->assertJsonPath('data.recent_shopify_lines_meta.total', 12)
        ->assertJsonCount(10, 'data.recent_shopify_lines');

    $page2 = $this->getJson("/api/v1/products/{$product->uuid}/demand?lines_page=2&lines_per_page=10");
    $page2->assertOk()
        ->assertJsonCount(2, 'data.recent_shopify_lines');
});

it('includes zero-sale weeks across the full detail window', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'SKU-DEM-ZERO-WEEKS',
        'description' => 'Zero week product',
        'type' => 'Others',
        'vendor' => 'Test',
    ]);

    ProductDemandDailyRollup::query()->create([
        'product_id' => $product->id,
        'sold_on' => now()->subDays(3)->toDateString(),
        'shopify_sold' => 4,
        'assumed_sold' => 0,
    ]);

    $response = $this->getJson("/api/v1/products/{$product->uuid}/demand");

    $response->assertOk();
    $weeks = $response->json('data.weekly_rollups');
    expect($weeks)->toHaveCount(53);

    $zeroWeeks = collect($weeks)->filter(fn (array $week): bool => ($week['total'] ?? 0) === 0);
    expect($zeroWeeks->count())->toBeGreaterThan(40);
});

it('queues maintenance shopify actions', function (): void {
    \Illuminate\Support\Facades\Queue::fake();

    $this->postJson('/api/v1/shopify/orders/historical-backfill')->assertOk();
    $this->postJson('/api/v1/shopify/demand/rebuild-rollups')->assertOk();
    $this->postJson('/api/v1/shopify/inventory/pull-to-products')->assertOk();

    \Illuminate\Support\Facades\Queue::assertPushed(
        \App\Jobs\Shopify\ShopifyOrderHistoricalBackfillJob::class,
        fn (\App\Jobs\Shopify\ShopifyOrderHistoricalBackfillJob $job): bool => $job->syncLogId > 0,
    );
    \Illuminate\Support\Facades\Queue::assertPushed(
        \App\Jobs\Shopify\RebuildProductDemandRollupsJob::class,
        fn (\App\Jobs\Shopify\RebuildProductDemandRollupsJob $job): bool => $job->syncLogId > 0,
    );
    \Illuminate\Support\Facades\Queue::assertPushed(
        \App\Jobs\Shopify\PullShopifyInventoryToProductsJob::class,
        fn (\App\Jobs\Shopify\PullShopifyInventoryToProductsJob $job): bool => $job->syncLogId > 0,
    );
});
