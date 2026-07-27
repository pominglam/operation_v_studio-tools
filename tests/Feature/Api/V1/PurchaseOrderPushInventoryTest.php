<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Product;
use App\Models\ProductSellingPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shopify\ShopifyInventoryLevel;
use App\Models\Shopify\ShopifyLocation;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowPushInventoryFinalizeService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

it('previews full product push for PO products with shopify mirror', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory,read_publications,write_publications']);

    $locationGid = 'gid://shopify/Location/9001';
    ShopifyLocation::query()->create([
        'gid' => $locationGid,
        'name' => 'Main warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114001',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114011',
        'sku' => 'PUSH-QTY-1',
        'description' => 'Push full product',
        'vendor' => 'Plamod',
        'handle' => 'push-qty-1',
        'latest_arrival' => true,
        'published_on_shopify' => true,
        'available_qty' => 12,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '19.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'PUSH-QTY-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 5,
    ]);

    $itemGid = 'gid://shopify/InventoryItem/9002';
    $productGid = 'gid://shopify/Product/9003';
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'push-qty-1',
        'title' => 'Push qty',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9004',
        'product_gid' => $productGid,
        'sku' => 'PUSH-QTY-1',
        'inventory_item_gid' => $itemGid,
    ]);
    ShopifyInventoryLevel::query()->create([
        'inventory_item_gid' => $itemGid,
        'location_gid' => $locationGid,
        'quantity_available' => 3,
    ]);

    $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory/preview")
        ->assertOk()
        ->assertJsonPath('data.push_count', 1)
        ->assertJsonPath('data.write_products_scope_ok', true)
        ->assertJsonPath('data.write_inventory_scope_ok', true)
        ->assertJsonPath('data.write_publications_scope_ok', true)
        ->assertJsonPath('data.products.0.sku', 'PUSH-QTY-1')
        ->assertJsonPath('data.products.0.push_action', 'update')
        ->assertJsonPath('data.products.0.selling_price', '19.99')
        ->assertJsonPath('data.products.0.erp_available_qty', 12);
});

it('previews push inventory with hold subtracted from shopify push qty', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory,read_publications,write_publications']);

    ShopifyLocation::query()->create([
        'gid' => 'gid://shopify/Location/9001',
        'name' => 'Main warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114031',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114032',
        'sku' => 'PUSH-HOLD-1',
        'description' => 'Push with hold',
        'vendor' => 'Plamod',
        'handle' => 'push-hold-1',
        'available_qty' => 12,
        'hold_qty' => 4,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '19.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'PUSH-HOLD-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $productGid = 'gid://shopify/Product/9005';
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'push-hold-1',
        'title' => 'Push hold',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9006',
        'product_gid' => $productGid,
        'sku' => 'PUSH-HOLD-1',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9007',
    ]);

    $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory/preview")
        ->assertOk()
        ->assertJsonPath('data.products.0.erp_available_qty', 12)
        ->assertJsonPath('data.products.0.erp_hold_qty', 4)
        ->assertJsonPath('data.products.0.shopify_push_qty', 8);
});

it('queues PO push inventory batch and completes via status poll', function (): void {
    Bus::fake();

    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory,read_publications,write_publications']);

    ShopifyLocation::query()->create([
        'gid' => 'gid://shopify/Location/9101',
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114021',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-07-20',
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114022',
        'sku' => 'PUSH-QTY-2',
        'description' => 'Push qty run',
        'vendor' => 'Plamod',
        'handle' => 'push-qty-2',
        'published_on_shopify' => true,
        'available_qty' => 7,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '9.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'PUSH-QTY-2',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $productGid = 'gid://shopify/Product/9103';
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'push-qty-2',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9104',
        'product_gid' => $productGid,
        'sku' => 'PUSH-QTY-2',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9102',
    ]);

    $response = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory");

    $response->assertAccepted()
        ->assertJsonPath('queued', 1);

    Bus::assertBatched(function ($batch): bool {
        return $batch->name === 'po_workflow_push_inventory'
            && count($batch->jobs) === 1
            && $batch->jobs[0] instanceof \App\Jobs\PushSelectedProductToShopifyJob;
    });
});

it('rejects push inventory when PO has no received date', function (): void {
    Bus::fake();

    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory,read_publications,write_publications']);

    ShopifyLocation::query()->create([
        'gid' => 'gid://shopify/Location/9101',
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114099',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114099',
        'sku' => 'PUSH-NO-RECV',
        'description' => 'No received date',
        'vendor' => 'Plamod',
        'handle' => 'push-no-recv',
        'published_on_shopify' => true,
        'available_qty' => 3,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '9.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'PUSH-NO-RECV',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    ShopifyProduct::query()->create([
        'gid' => 'gid://shopify/Product/9199',
        'handle' => 'push-no-recv',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9198',
        'product_gid' => 'gid://shopify/Product/9199',
        'sku' => 'PUSH-NO-RECV',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9197',
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory")
        ->assertUnprocessable()
        ->assertJsonPath('ok', false)
        ->assertJsonFragment([
            'message' => 'Set a received date on this purchase order before pushing to Shopify. Unreceived POs are ignored for Latest Arrivals storefront ordering.',
        ]);

    Bus::assertNothingBatched();
});

it('pushes full product via productSet for PO products', function (): void {
    config(['queue.default' => 'sync']);
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory,read_publications,write_publications']);
    Cache::forget('shopify.publication_ids');

    $locationGid = 'gid://shopify/Location/9101';
    ShopifyLocation::query()->create([
        'gid' => $locationGid,
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114021',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-07-20',
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114022',
        'sku' => 'PUSH-QTY-2',
        'description' => 'Push qty run',
        'vendor' => 'Plamod',
        'handle' => 'push-qty-2',
        'published_on_shopify' => true,
        'available_qty' => 7,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '9.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'PUSH-QTY-2',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $productGid = 'gid://shopify/Product/9103';
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'push-qty-2',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9104',
        'product_gid' => $productGid,
        'sku' => 'PUSH-QTY-2',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9102',
    ]);

    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapProductSet($productGid, 'push-qty-2'));
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapPublications([
        'gid://shopify/Publication/1',
        'gid://shopify/Publication/2',
        'gid://shopify/Publication/3',
    ]));
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapPublishablePublish());
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapProductMirrorNode(
        $productGid,
        'push-qty-2',
        'PUSH-QTY-2',
        [
            'variant_gid' => 'gid://shopify/ProductVariant/9104',
            'inventory_item_gid' => 'gid://shopify/InventoryItem/9102',
        ],
    ));
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapInventoryItem(
        'gid://shopify/InventoryItem/9102',
        [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [],
        ],
    ));
    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $queued = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory")
        ->assertAccepted()
        ->assertJsonPath('queued', 1);

    $batchId = (string) $queued->json('batch_id');
    expect($batchId)->not->toBe('');

    $statusRes = $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory/status?batch_id={$batchId}")
        ->assertOk();

    if ($statusRes->json('data.phase') === 'finalizing') {
        app(PurchaseOrderWorkflowPushInventoryFinalizeService::class)->finalize($po->uuid, $batchId);
    }

    $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory/status?batch_id={$batchId}")
        ->assertOk()
        ->assertJsonPath('data.phase', 'complete')
        ->assertJsonPath('data.summary.updated', 1)
        ->assertJsonPath('data.summary.failed', 0);
});

it('previews push for PO products with DRAFT shopify mirror', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products,write_inventory,read_publications,write_publications']);

    ShopifyLocation::query()->create([
        'gid' => 'gid://shopify/Location/9301',
        'name' => 'Main warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114041',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114042',
        'sku' => 'PUSH-DRAFT-1',
        'description' => 'Draft mirror product',
        'vendor' => 'Plamod',
        'handle' => 'push-draft-1',
        'published_on_shopify' => false,
        'available_qty' => 5,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '12.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'PUSH-DRAFT-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $productGid = 'gid://shopify/Product/9303';
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'push-draft-1',
        'title' => 'Draft',
        'status' => 'DRAFT',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/9304',
        'product_gid' => $productGid,
        'sku' => 'PUSH-DRAFT-1',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/9302',
    ]);

    $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory/preview")
        ->assertOk()
        ->assertJsonPath('data.push_count', 1)
        ->assertJsonPath('data.products.0.sku', 'PUSH-DRAFT-1')
        ->assertJsonPath('data.products.0.push_action', 'update')
        ->assertJsonPath('data.products.0.push_eligible', true)
        ->assertJsonPath('data.products.0.skip_reason', null);
});

it('previews PO products sorted for Latest Arrivals order', function (): void {
    config(['shopify.oauth_scopes' => 'write_products,write_inventory']);

    ShopifyLocation::query()->create([
        'gid' => 'gid://shopify/Location/9401',
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114041',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $eg = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114042',
        'sku' => 'SORT-EG',
        'description' => 'EG kit',
        'type' => 'EG',
        'vendor' => 'Plamod',
        'handle' => 'sort-eg',
        'available_qty' => 1,
        'created_at' => now()->subDays(1),
    ]);
    $pg = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114043',
        'sku' => 'SORT-PG',
        'description' => 'PG kit',
        'type' => 'PG',
        'vendor' => 'Plamod',
        'handle' => 'sort-pg',
        'available_qty' => 1,
        'created_at' => now()->subDays(10),
    ]);
    $hgOld = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114044',
        'sku' => 'SORT-HG-OLD',
        'description' => 'HG old',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'handle' => 'sort-hg-old',
        'available_qty' => 1,
        'created_at' => now()->subDays(5),
    ]);
    $hgNew = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114045',
        'sku' => 'SORT-HG-NEW',
        'description' => 'HG new',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'handle' => 'sort-hg-new',
        'available_qty' => 1,
        'created_at' => now()->subHours(1),
    ]);

    foreach ([$eg, $pg, $hgOld, $hgNew] as $product) {
        ProductSellingPrice::query()->create([
            'product_id' => $product->id,
            'product_uuid' => $product->uuid,
            'selling_price' => '9.99',
            'currency' => 'CAD',
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
        $productGid = 'gid://shopify/Product/'.substr((string) $product->uuid, -4);
        ShopifyProduct::query()->create([
            'gid' => $productGid,
            'handle' => (string) $product->handle,
            'title' => (string) $product->description,
            'status' => 'ACTIVE',
        ]);
        ShopifyProductVariant::query()->create([
            'gid' => 'gid://shopify/ProductVariant/'.substr((string) $product->uuid, -4),
            'product_gid' => $productGid,
            'sku' => (string) $product->sku,
            'inventory_item_gid' => 'gid://shopify/InventoryItem/'.substr((string) $product->uuid, -4),
        ]);
    }

    $response = $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory/preview")
        ->assertOk();

    $skus = collect($response->json('data.products'))->pluck('sku')->all();

    expect($skus)->toBe(['SORT-PG', 'SORT-HG-NEW', 'SORT-HG-OLD', 'SORT-EG']);
});

it('previews MGEX before MG and Mega Size Model after PG', function (): void {
    config(['shopify.oauth_scopes' => 'write_products,write_inventory']);

    ShopifyLocation::query()->create([
        'gid' => 'gid://shopify/Location/9501',
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114051',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $mg = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114052',
        'sku' => 'SORT-MG',
        'description' => 'MG 1/100 kit',
        'type' => 'MG',
        'vendor' => 'Plamod',
        'handle' => 'sort-mg',
        'available_qty' => 1,
    ]);
    $mgex = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114053',
        'sku' => 'SORT-MGEX',
        'description' => 'MGEX 1/100 kit',
        'type' => 'MGEX',
        'vendor' => 'Plamod',
        'handle' => 'sort-mgex',
        'available_qty' => 1,
    ]);
    $mega = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114054',
        'sku' => 'SORT-MEGA',
        'description' => 'Mega Size Model - 1/48 kit',
        'type' => null,
        'vendor' => 'Plamod',
        'handle' => 'sort-mega',
        'available_qty' => 1,
    ]);
    $pg = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114055',
        'sku' => 'SORT-PG-2',
        'description' => 'PG kit',
        'type' => 'PG',
        'vendor' => 'Plamod',
        'handle' => 'sort-pg-2',
        'available_qty' => 1,
    ]);

    foreach ([$mg, $mgex, $mega, $pg] as $product) {
        ProductSellingPrice::query()->create([
            'product_id' => $product->id,
            'product_uuid' => $product->uuid,
            'selling_price' => '9.99',
            'currency' => 'CAD',
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
        $productGid = 'gid://shopify/Product/'.substr((string) $product->uuid, -4);
        ShopifyProduct::query()->create([
            'gid' => $productGid,
            'handle' => (string) $product->handle,
            'title' => (string) $product->description,
            'status' => 'ACTIVE',
        ]);
        ShopifyProductVariant::query()->create([
            'gid' => 'gid://shopify/ProductVariant/'.substr((string) $product->uuid, -4),
            'product_gid' => $productGid,
            'sku' => (string) $product->sku,
            'inventory_item_gid' => 'gid://shopify/InventoryItem/'.substr((string) $product->uuid, -4),
        ]);
    }

    $skus = collect(
        $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory/preview")
            ->assertOk()
            ->json('data.products'),
    )->pluck('sku')->all();

    expect($skus)->toBe(['SORT-PG-2', 'SORT-MEGA', 'SORT-MGEX', 'SORT-MG']);
});

it('skips products without selling price on push preview', function (): void {
    config(['shopify.oauth_scopes' => 'write_products,write_inventory']);

    ShopifyLocation::query()->create([
        'gid' => 'gid://shopify/Location/9201',
        'name' => 'Warehouse',
        'is_active' => true,
        'fulfills_online_orders' => true,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114031',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000114032',
        'sku' => 'PUSH-NO-PRICE',
        'description' => 'No price',
        'vendor' => 'Plamod',
        'available_qty' => 4,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'PUSH-NO-PRICE',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/push-inventory/preview")
        ->assertOk()
        ->assertJsonPath('data.push_count', 0)
        ->assertJsonPath('data.products.0.skip_reason', 'missing_selling_price');
});
