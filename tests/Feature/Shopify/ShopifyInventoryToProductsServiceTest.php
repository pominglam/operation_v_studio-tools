<?php

declare(strict_types=1);

use App\Jobs\Shopify\PullShopifyInventoryToProductsJob;
use App\Models\Product;
use App\Models\Shopify\ShopifyInventoryItem;
use App\Models\Shopify\ShopifyInventoryLevel;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Models\Shopify\ShopifySyncLog;
use App\Services\Maintenance\ExternalAccessSettingsService;
use App\Services\Shopify\Admin\Inventory\ShopifyInventoryToProductsService;
use App\Services\Shopify\Admin\ShopifyMaintenanceRunLogger;
use App\Services\Shopify\Admin\ShopifyOpsStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app(ExternalAccessSettingsService::class)->setEnabled(false);
});

/**
 * @return array{product_gid: string, inventory_item_gid: string}
 */
function seedShopifyCatalogVariant(string $sku, string $productStatus, string $suffix): array
{
    $productGid = "gid://shopify/Product/test-{$suffix}";
    $inventoryItemGid = "gid://shopify/InventoryItem/test-{$suffix}";

    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => "handle-{$suffix}",
        'title' => "Product {$suffix}",
        'status' => $productStatus,
    ]);

    ShopifyProductVariant::query()->create([
        'gid' => "gid://shopify/ProductVariant/test-{$suffix}",
        'product_gid' => $productGid,
        'sku' => $sku,
        'inventory_item_gid' => $inventoryItemGid,
    ]);

    ShopifyInventoryItem::query()->create([
        'gid' => $inventoryItemGid,
        'sku' => $sku,
        'tracked' => true,
    ]);

    return [
        'product_gid' => $productGid,
        'inventory_item_gid' => $inventoryItemGid,
    ];
}

it('clamps negative shopify inventory to zero on products available_qty', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'SKU-INV-NEG',
        'description' => 'Negative inventory product',
        'type' => 'Others',
        'vendor' => 'Test',
        'available_qty' => 5,
    ]);

    $catalog = seedShopifyCatalogVariant('SKU-INV-NEG', 'ACTIVE', 'neg');
    ShopifyInventoryLevel::query()->create([
        'level_gid' => 'gid://shopify/InventoryLevel/9001',
        'inventory_item_gid' => $catalog['inventory_item_gid'],
        'location_gid' => 'gid://shopify/Location/1',
        'quantity_available' => -1,
    ]);

    $result = app(ShopifyInventoryToProductsService::class)->pullToAvailableQty(syncLevelsFirst: false);

    expect($result)->toMatchArray(['matched' => 1, 'updated' => 1, 'skipped' => 0]);

    $product->refresh();
    expect($product->available_qty)->toBe(0);
});

it('sums multi-location inventory and updates matched products', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'SKU-INV-SUM',
        'description' => 'Multi-location inventory product',
        'type' => 'Others',
        'vendor' => 'Test',
        'available_qty' => 0,
    ]);

    $catalog = seedShopifyCatalogVariant('SKU-INV-SUM', 'ACTIVE', 'sum');
    ShopifyInventoryLevel::query()->create([
        'level_gid' => 'gid://shopify/InventoryLevel/9002a',
        'inventory_item_gid' => $catalog['inventory_item_gid'],
        'location_gid' => 'gid://shopify/Location/1',
        'quantity_available' => 3,
    ]);
    ShopifyInventoryLevel::query()->create([
        'level_gid' => 'gid://shopify/InventoryLevel/9002b',
        'inventory_item_gid' => $catalog['inventory_item_gid'],
        'location_gid' => 'gid://shopify/Location/2',
        'quantity_available' => 4,
    ]);

    $result = app(ShopifyInventoryToProductsService::class)->pullToAvailableQty(syncLevelsFirst: false);

    expect($result)->toMatchArray(['matched' => 1, 'updated' => 1, 'skipped' => 0]);

    $product->refresh();
    expect($product->available_qty)->toBe(7);
});

it('ignores inventory levels for archived shopify products when pulling qty', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'MS-104',
        'description' => 'Stedi Nipper MS-104',
        'type' => 'Others',
        'vendor' => 'Test',
        'available_qty' => 15,
    ]);

    $archived = seedShopifyCatalogVariant('MS-104', 'ARCHIVED', 'archived-ms104');
    ShopifyInventoryLevel::query()->create([
        'level_gid' => 'gid://shopify/InventoryLevel/archived-ms104',
        'inventory_item_gid' => $archived['inventory_item_gid'],
        'location_gid' => 'gid://shopify/Location/1',
        'quantity_available' => 15,
    ]);

    $active = seedShopifyCatalogVariant('MS-104', 'ACTIVE', 'active-ms104');
    ShopifyInventoryLevel::query()->create([
        'level_gid' => 'gid://shopify/InventoryLevel/active-ms104',
        'inventory_item_gid' => $active['inventory_item_gid'],
        'location_gid' => 'gid://shopify/Location/1',
        'quantity_available' => 1,
    ]);

    $result = app(ShopifyInventoryToProductsService::class)->pullToAvailableQty(syncLevelsFirst: false);

    expect($result)->toMatchArray(['matched' => 1, 'updated' => 1, 'skipped' => 0]);

    $product->refresh();
    expect($product->available_qty)->toBe(1);
});

it('does not fail when only active variant reports negative shopify inventory', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => '5055727',
        'description' => 'Stedi Nipper MS-104',
        'type' => 'Others',
        'vendor' => 'Test',
        'available_qty' => 15,
    ]);

    $catalog = seedShopifyCatalogVariant('5055727', 'ACTIVE', 'ms104-negative');
    ShopifyInventoryLevel::query()->create([
        'level_gid' => 'gid://shopify/InventoryLevel/ms104-negative',
        'inventory_item_gid' => $catalog['inventory_item_gid'],
        'location_gid' => 'gid://shopify/Location/1',
        'quantity_available' => -1,
    ]);

    $result = app(ShopifyInventoryToProductsService::class)->pullToAvailableQty(syncLevelsFirst: false);

    expect($result)->toMatchArray(['matched' => 1, 'updated' => 1, 'skipped' => 0]);

    $product->refresh();
    expect($product->available_qty)->toBe(0);
});

it('completes inventory pull job without failing on negative shopify quantities', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'SKU-INV-JOB',
        'description' => 'Inventory pull job product',
        'type' => 'Others',
        'vendor' => 'Test',
        'available_qty' => 2,
    ]);

    $catalog = seedShopifyCatalogVariant('SKU-INV-JOB', 'ACTIVE', 'job');
    ShopifyInventoryLevel::query()->create([
        'level_gid' => 'gid://shopify/InventoryLevel/9003',
        'inventory_item_gid' => $catalog['inventory_item_gid'],
        'location_gid' => 'gid://shopify/Location/1',
        'quantity_available' => -2,
    ]);

    $log = app(ShopifyMaintenanceRunLogger::class)->queue(ShopifyOpsStatusService::SYNC_KEY_INVENTORY_PULL);

    $job = new PullShopifyInventoryToProductsJob($log->id);
    $job->handle(
        app(ShopifyInventoryToProductsService::class),
        app(ShopifyMaintenanceRunLogger::class),
    );

    $log->refresh();
    expect($log->status)->toBe('completed')
        ->and($log->counts_json)->toMatchArray(['matched' => 1, 'updated' => 1, 'skipped' => 0]);

    $product->refresh();
    expect($product->available_qty)->toBe(0);
});

it('reports running status when sync log is running even if job row is reserved', function (): void {
    ShopifySyncLog::query()->create([
        'sync_key' => ShopifyOpsStatusService::SYNC_KEY_INVENTORY_PULL,
        'status' => 'running',
        'started_at' => now()->subMinute(),
        'finished_at' => null,
        'counts_json' => [],
    ]);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => PullShopifyInventoryToProductsJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => PullShopifyInventoryToProductsJob::class,
                'command' => serialize(new PullShopifyInventoryToProductsJob(99)),
            ],
        ]),
        'attempts' => 1,
        'reserved_at' => now()->timestamp,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    $this->getJson('/api/v1/shopify/settings')
        ->assertOk()
        ->assertJsonPath('data.tasks.3.key', ShopifyOpsStatusService::SYNC_KEY_INVENTORY_PULL)
        ->assertJsonPath('data.tasks.3.status', 'running')
        ->assertJsonPath('data.tasks.3.queued', false);
});
