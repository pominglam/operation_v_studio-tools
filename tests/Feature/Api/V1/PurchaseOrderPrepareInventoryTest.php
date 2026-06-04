<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shopify\ShopifyInventoryLevel;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Models\Shopify\ShopifySyncLog;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

it('skips full store sync on prepare when catalog mirror is fresh within one hour', function (): void {
    $finished = now()->subMinutes(20);
    ShopifySyncLog::query()->create([
        'sync_key' => 'products',
        'status' => 'completed',
        'started_at' => $finished->copy()->subMinute(),
        'finished_at' => $finished,
    ]);
    ShopifySyncLog::query()->create([
        'sync_key' => 'inventory_levels',
        'status' => 'completed',
        'started_at' => $finished->copy()->subMinute(),
        'finished_at' => $finished,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000112001',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000112002',
        'sku' => 'PREP-FRESH-1',
        'description' => 'Prepare fresh',
        'vendor' => 'Plamod',
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'PREP-FRESH-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 2,
        'qty_received' => 2,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/prepare-inventory")
        ->assertOk()
        ->assertJsonPath('data.sync_mode', 'skipped_mirror_fresh')
        ->assertJsonPath('data.mirror_fresh', true)
        ->assertJsonPath('data.lines_validated', 1);
});

it('refreshes only PO SKUs on prepare when catalog mirror is stale', function (): void {
    $product = ShopifyProduct::query()->create([
        'gid' => 'gid://shopify/Product/9001',
        'handle' => 'prep-po-sku',
        'title' => 'Prep PO SKU',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9001',
        'product_gid' => $product->gid,
        'sku' => 'PREP-PO-1',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9001',
        'inventory_quantity' => null,
    ]);

    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapInventoryItem(
        'gid://shopify/InventoryItem/9001',
        [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [[
                'id' => 'gid://shopify/InventoryLevel/1',
                'updatedAt' => '2025-01-01T00:00:00Z',
                'location' => ['id' => 'gid://shopify/Location/1'],
                'quantities' => [['name' => 'available', 'quantity' => 7]],
            ]],
        ],
    ));
    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000112011',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000112012',
        'sku' => 'PREP-PO-1',
        'description' => 'Prepare PO scoped',
        'vendor' => 'Plamod',
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'PREP-PO-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 3,
        'qty_received' => 3,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/prepare-inventory")
        ->assertOk()
        ->assertJsonPath('data.sync_mode', 'po_inventory_refresh')
        ->assertJsonPath('data.mirror_fresh', false)
        ->assertJsonPath('data.skus_refreshed', 1)
        ->assertJsonPath('data.inventory_items_refreshed', 1)
        ->assertJsonPath('data.shopify_quantities.0.shopify_available', 7);

    expect(ShopifyInventoryLevel::query()->where('inventory_item_gid', 'gid://shopify/InventoryItem/9001')->exists())
        ->toBeTrue();
});
