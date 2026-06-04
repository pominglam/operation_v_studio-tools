<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

it('clears latest_arrival for products on purchase orders older than four weeks', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);
    $oldPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113001',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => now()->subWeeks(5)->toDateString(),
    ]);
    $recentPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113002',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => now()->subWeeks(1)->toDateString(),
    ]);

    $oldProduct = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113011',
        'sku' => 'STALE-LA-1',
        'description' => 'Old PO product',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
        'published_on_shopify' => true,
    ]);
    $recentProduct = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113012',
        'sku' => 'STALE-LA-2',
        'description' => 'Recent PO product',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
        'published_on_shopify' => true,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $oldPo->id,
        'product_id' => $oldProduct->id,
        'sku' => 'STALE-LA-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $recentPo->id,
        'product_id' => $recentProduct->id,
        'sku' => 'STALE-LA-2',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $this->postJson('/api/v1/maintenance/clear-stale-latest-arrival')
        ->assertOk()
        ->assertJsonPath('data.products_cleared', 1)
        ->assertJsonPath('data.purchase_orders_matched', 1);

    $oldProduct->refresh();
    $recentProduct->refresh();
    expect($oldProduct->latest_arrival)->toBeFalse();
    expect($recentProduct->latest_arrival)->toBeTrue();
    expect($oldProduct->published_on_shopify)->toBeTrue();
});

it('does not clear latest_arrival when product is also on a purchase order within four weeks', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);

    $oldPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113061',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => now()->subWeeks(5)->toDateString(),
    ]);
    $recentPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113062',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => now()->subWeeks(1)->toDateString(),
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113063',
        'sku' => 'STALE-LA-BOTH-PO',
        'description' => 'On old and recent PO',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);

    foreach ([$oldPo, $recentPo] as $po) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => 'STALE-LA-BOTH-PO',
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $shopifyGid = 'gid://shopify/Product/99021';
    ShopifyProduct::query()->create([
        'gid' => $shopifyGid,
        'handle' => 'stale-la-both-po',
        'title' => 'Both POs',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/99022',
        'product_gid' => $shopifyGid,
        'sku' => 'STALE-LA-BOTH-PO',
    ]);

    $fake = new FakeShopifyAdminGraphQlClient;
    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $this->postJson('/api/v1/maintenance/clear-stale-latest-arrival')
        ->assertOk()
        ->assertJsonPath('data.products_cleared', 0)
        ->assertJsonPath('data.shopify_tags_removed', 0);

    expect($product->refresh()->latest_arrival)->toBeTrue();
});

it('removes latest arrival tag on shopify for cleared products with mirror gid', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);

    $oldPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113031',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => now()->subWeeks(5)->toDateString(),
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113032',
        'sku' => 'STALE-LA-SHOPIFY',
        'description' => 'Shopify mirrored',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $oldPo->id,
        'product_id' => $product->id,
        'sku' => 'STALE-LA-SHOPIFY',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $shopifyGid = 'gid://shopify/Product/99001';
    ShopifyProduct::query()->create([
        'gid' => $shopifyGid,
        'handle' => 'stale-la-shopify',
        'title' => 'Stale LA',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/99002',
        'product_gid' => $shopifyGid,
        'sku' => 'STALE-LA-SHOPIFY',
    ]);

    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapTagsRemove($shopifyGid));
    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $this->postJson('/api/v1/maintenance/clear-stale-latest-arrival')
        ->assertOk()
        ->assertJsonPath('data.products_cleared', 1)
        ->assertJsonPath('data.shopify_tags_removed', 1)
        ->assertJsonPath('data.shopify_skipped_no_gid', 0)
        ->assertJsonPath('data.shopify_tag_removals_failed', 0);

    expect($product->refresh()->latest_arrival)->toBeFalse();
});

it('does not call shopify when latest_arrival was already false', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);

    $oldPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113051',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => now()->subWeeks(5)->toDateString(),
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113052',
        'sku' => 'STALE-LA-ALREADY-FALSE',
        'description' => 'Already cleared',
        'vendor' => 'Plamod',
        'latest_arrival' => false,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $oldPo->id,
        'product_id' => $product->id,
        'sku' => 'STALE-LA-ALREADY-FALSE',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $shopifyGid = 'gid://shopify/Product/99011';
    ShopifyProduct::query()->create([
        'gid' => $shopifyGid,
        'handle' => 'stale-la-already-false',
        'title' => 'Already false',
        'status' => 'ACTIVE',
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/99012',
        'product_gid' => $shopifyGid,
        'sku' => 'STALE-LA-ALREADY-FALSE',
    ]);

    $fake = new FakeShopifyAdminGraphQlClient;
    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $this->postJson('/api/v1/maintenance/clear-stale-latest-arrival')
        ->assertOk()
        ->assertJsonPath('data.products_cleared', 0)
        ->assertJsonPath('data.shopify_tags_removed', 0)
        ->assertJsonPath('data.shopify_skipped_no_gid', 0);
});

it('skips shopify when product has no mirror gid', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);

    $oldPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113041',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => now()->subWeeks(5)->toDateString(),
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113042',
        'sku' => 'STALE-LA-NO-MIRROR',
        'description' => 'No mirror',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $oldPo->id,
        'product_id' => $product->id,
        'sku' => 'STALE-LA-NO-MIRROR',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $this->postJson('/api/v1/maintenance/clear-stale-latest-arrival')
        ->assertOk()
        ->assertJsonPath('data.products_cleared', 1)
        ->assertJsonPath('data.shopify_tags_removed', 0)
        ->assertJsonPath('data.shopify_skipped_no_gid', 1);
});

it('uses created_at when purchase order has no received_date', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);
    $oldPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113021',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
    ]);
    $oldPo->forceFill(['created_at' => now()->subWeeks(6)])->save();

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000113022',
        'sku' => 'STALE-LA-3',
        'description' => 'No received date PO',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $oldPo->id,
        'product_id' => $p->id,
        'sku' => 'STALE-LA-3',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $this->postJson('/api/v1/purchase-orders/00000000-0000-0000-0000-000000113099/workflow-actions/clear-stale-latest-arrival')
        ->assertOk()
        ->assertJsonPath('data.products_cleared', 1);

    expect($p->refresh()->latest_arrival)->toBeFalse();
});
