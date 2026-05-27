<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
use App\Models\ProductSellingPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowPrepareInventoryService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowPullHandlesService;
use App\Support\Pricing\CharmPricingCalculator;

it('auto-checks completed workflow steps on verify without unchecking manual flags', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111211',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'workflow_checklist_json' => [
            'import_po' => false,
            'export_to_shopify_get_handles' => true,
        ],
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111212',
        'sku' => 'WF-SKU-1',
        'barcode' => '9990001',
        'description' => 'Workflow product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '6.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'WF-SKU-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 2,
        'qty_received' => 2,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-verify");

    $res->assertOk();
    $res->assertJsonPath('data.steps.import_po.done', true);
    $res->assertJsonPath('data.steps.import_po.newly_checked', true);
    $res->assertJsonPath('data.steps.set_selling_price.done', true);
    $res->assertJsonPath('data.steps.ensure_all_products_have_barcode.done', true);
    $res->assertJsonPath('data.purchase_order.workflow_checklist.export_to_shopify_get_handles', true);

    $po->refresh();
    expect($po->workflow_checklist_json['import_po'] ?? null)->toBeTrue();
});

it('previews PO set prices grouped by new, updates, unchanged, and skipped', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111220',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $newProduct = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111221',
        'sku' => 'PREVIEW-NEW',
        'barcode' => '9990010',
        'description' => 'New price product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    $updateProduct = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111222',
        'sku' => 'PREVIEW-UPDATE',
        'barcode' => '9990011',
        'description' => 'Raise price product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $updateProduct->id,
        'product_uuid' => $updateProduct->uuid,
        'selling_price' => '5.99',
        'currency' => 'CAD',
    ]);

    $keptHigherProduct = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111224',
        'sku' => 'PREVIEW-KEEP',
        'barcode' => '9990014',
        'description' => 'Keep higher price product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $keptHigherProduct->id,
        'product_uuid' => $keptHigherProduct->uuid,
        'selling_price' => '10.99',
        'currency' => 'CAD',
    ]);

    $unchangedProduct = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111223',
        'sku' => 'PREVIEW-SAME',
        'barcode' => '9990012',
        'description' => 'Unchanged price product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $unchangedProduct->id,
        'product_uuid' => $unchangedProduct->uuid,
        'selling_price' => '6.99',
        'currency' => 'CAD',
    ]);

    foreach ([$newProduct, $updateProduct, $unchangedProduct, $keptHigherProduct] as $product) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => (string) $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $res = $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/set-prices/preview");

    $res->assertOk();
    $res->assertJsonPath('data.apply_count', 2);
    $res->assertJsonPath('data.new_prices.0.sku', 'PREVIEW-NEW');
    $res->assertJsonPath('data.new_prices.0.proposed_price', '6.99');
    $res->assertJsonPath('data.new_prices.0.proposed_multiplier', '1.75');
    $res->assertJsonPath('data.updates.0.sku', 'PREVIEW-UPDATE');
    $res->assertJsonPath('data.updates.0.current_price', '5.99');
    $res->assertJsonPath('data.updates.0.current_multiplier', '1.50');
    $res->assertJsonPath('data.updates.0.proposed_multiplier', '1.75');

    $unchanged = collect($res->json('data.unchanged'))->keyBy('sku');
    expect($unchanged->has('PREVIEW-SAME'))->toBeTrue();
    expect($unchanged->get('PREVIEW-KEEP')['keep_reason'] ?? null)->toBe('current_higher_than_formula');
    expect($unchanged->get('PREVIEW-KEEP')['current_multiplier'] ?? null)->toBe('2.75');
    expect($unchanged->get('PREVIEW-KEEP')['proposed_multiplier'] ?? null)->toBe('1.75');
});

it('sets PO product prices at 1.5x landed cost rounded to x.99', function (): void {
    expect(CharmPricingCalculator::sellingPriceX99FromCost('4.00', '1.5'))->toBe('6.99');

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111221',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111222',
        'sku' => 'PRICE-SKU',
        'barcode' => '9990002',
        'description' => 'Price product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'PRICE-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/set-prices");

    $res->assertOk();
    $res->assertJsonPath('data.summary.updated', 1);
    $res->assertJsonPath('data.summary.skipped_unchanged', 0);

    $this->assertDatabaseHas('product_selling_prices', [
        'product_id' => $p->id,
        'selling_price' => '6.99',
    ]);
});

it('does not reduce existing price when formula is lower', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111225',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111226',
        'sku' => 'PRICE-KEEP-SKU',
        'barcode' => '9990013',
        'description' => 'Existing priced product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '12.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'PRICE-KEEP-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/set-prices")
        ->assertOk()
        ->assertJsonPath('data.summary.updated', 0);

    $this->assertDatabaseHas('product_selling_prices', [
        'product_id' => $p->id,
        'selling_price' => '12.99',
    ]);
});

it('raises existing price when formula is higher', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111227',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111228',
        'sku' => 'PRICE-RAISE-SKU',
        'barcode' => '9990015',
        'description' => 'Existing low priced product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'latest_landed_unit_cost' => '4.00',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '5.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'PRICE-RAISE-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/set-prices")
        ->assertOk()
        ->assertJsonPath('data.summary.updated', 1);

    $this->assertDatabaseHas('product_selling_prices', [
        'product_id' => $p->id,
        'selling_price' => '6.99',
    ]);
});

it('previews PO export to Shopify for products on PO missing handle', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111230',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $readyProduct = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111231',
        'sku' => 'EXPORT-READY',
        'barcode' => '9990020',
        'description' => 'Ready export product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'handle' => null,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $readyProduct->id,
        'product_uuid' => $readyProduct->uuid,
        'selling_price' => '6.99',
        'currency' => 'CAD',
    ]);

    $missingPriceProduct = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111232',
        'sku' => 'EXPORT-NO-PRICE',
        'barcode' => '9990021',
        'description' => 'Missing price product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'handle' => null,
    ]);

    $hasHandleProduct = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111233',
        'sku' => 'EXPORT-HAS-HANDLE',
        'barcode' => '9990022',
        'description' => 'Already has handle',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'handle' => 'existing-handle',
    ]);

    foreach ([$readyProduct, $missingPriceProduct, $hasHandleProduct] as $product) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => (string) $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $res = $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/export-shopify-content/preview");

    $res->assertOk();
    $res->assertJsonPath('data.export_type', 'shopify_content_no_inventory');
    $res->assertJsonPath('data.export_type_label', 'Shopify content (images + description, no inventory)');
    $res->assertJsonPath('data.write_scope_ok', true);
    $res->assertJsonPath('data.export_count', 1);
    $res->assertJsonPath('data.product_uuids.0', (string) $readyProduct->uuid);

    $products = collect($res->json('data.products'))->keyBy('sku');
    expect($products->has('EXPORT-READY'))->toBeTrue();
    expect($products->get('EXPORT-READY')['handle'] ?? '')->toBeIn([null, '']);
    expect($products->get('EXPORT-READY')['export_eligible'] ?? false)->toBeTrue();
    expect($products->has('EXPORT-NO-PRICE'))->toBeTrue();
    expect($products->get('EXPORT-NO-PRICE')['export_eligible'] ?? true)->toBeFalse();
    expect($products->has('EXPORT-HAS-HANDLE'))->toBeFalse();
});

it('previews PO export to Shopify for re-ordered products missing handle', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);

    $firstPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111248',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $reorderedProduct = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111249',
        'sku' => 'REORDER-NO-HANDLE',
        'barcode' => '9990024',
        'description' => 'Re-ordered without handle',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'handle' => null,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $reorderedProduct->id,
        'product_uuid' => $reorderedProduct->uuid,
        'selling_price' => '6.99',
        'currency' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $firstPo->id,
        'product_id' => $reorderedProduct->id,
        'sku' => 'REORDER-NO-HANDLE',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $secondPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111250',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $secondPo->id,
        'product_id' => $reorderedProduct->id,
        'sku' => 'REORDER-NO-HANDLE',
        'vendor' => 'Plamod',
        'qty_ordered' => 2,
    ]);

    $res = $this->getJson("/api/v1/purchase-orders/{$secondPo->uuid}/workflow-actions/export-shopify-content/preview");

    $res->assertOk();
    $res->assertJsonPath('data.export_count', 1);
    $res->assertJsonPath('data.product_uuids.0', (string) $reorderedProduct->uuid);
    expect(collect($res->json('data.products'))->pluck('sku')->all())->toContain('REORDER-NO-HANDLE');
});

it('pushes new PO products to Shopify and stores returned handles', function (): void {
    config(['shopify.oauth_scopes' => 'read_products,write_products']);

    $fake = new \Tests\Fakes\FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(\Tests\Fakes\FakeShopifyAdminGraphQlClient::wrapProductSet(
        'gid://shopify/Product/9001',
        'export-ready-handle',
    ));
    $this->app->instance(\App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface::class, $fake);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111235',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111236',
        'sku' => 'PUSH-SKU',
        'barcode' => '9990023',
        'description' => 'Push export product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'handle' => null,
        'published_on_shopify' => true,
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '6.99',
        'currency' => 'CAD',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $product->id,
        'source' => 'hlj',
        'description_html' => '<p>Test body</p>',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'PUSH-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/export-shopify-content/push");

    $res->assertOk();
    $res->assertJsonPath('data.summary.created', 1);
    $res->assertJsonPath('data.summary.failed', 0);
    $res->assertJsonPath('data.summary.results.0.handle', 'export-ready-handle');

    $product->refresh();
    expect($product->handle)->toBe('export-ready-handle');
});

it('auto-checks export to shopify step when all PO products have handles', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111237',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'workflow_checklist_json' => [
            'export_to_shopify_get_handles' => false,
        ],
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111238',
        'sku' => 'HANDLE-DONE-SKU',
        'barcode' => '9990024',
        'description' => 'Product with handle',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'handle' => 'existing-handle',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'HANDLE-DONE-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-verify");

    $res->assertOk();
    $res->assertJsonPath('data.steps.export_to_shopify_get_handles.done', true);
    $res->assertJsonPath('data.steps.export_to_shopify_get_handles.newly_checked', true);

    $po->refresh();
    expect($po->workflow_checklist_json['export_to_shopify_get_handles'] ?? null)->toBeTrue();
});

it('marks all PO products as latest arrival and published locally', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111231',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111232',
        'sku' => 'FLAG-SKU',
        'barcode' => '9990003',
        'description' => 'Flag product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'latest_arrival' => false,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'FLAG-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/mark-latest-arrival-published");

    $res->assertOk();
    $p->refresh();
    expect($p->published_on_shopify)->toBeTrue();
    expect($p->latest_arrival)->toBeTrue();
});

it('pulls handles from shopify mirror for new PO products missing handle', function (): void {
    ShopifyProduct::query()->create([
        'gid' => 'gid://shopify/Product/1',
        'handle' => 'pulled-handle',
        'title' => 'Shopify product',
    ]);

    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/1',
        'product_gid' => 'gid://shopify/Product/1',
        'sku' => 'HANDLE-SKU',
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111241',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111242',
        'sku' => 'HANDLE-SKU',
        'barcode' => '9990004',
        'description' => 'Handle product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'handle' => null,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'HANDLE-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $service = app(PurchaseOrderWorkflowPullHandlesService::class);
    $summary = $service->applyHandlesFromMirror($po->uuid);

    expect($summary['updated'])->toBe(1);
    $p->refresh();
    expect($p->handle)->toBe('pulled-handle');
});

it('previews pull handles for new-on-PO products missing local handle', function (): void {
    ShopifyProduct::query()->create([
        'gid' => 'gid://shopify/Product/2',
        'handle' => 'mirror-handle',
        'title' => 'Mirror product',
    ]);

    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/2',
        'product_gid' => 'gid://shopify/Product/2',
        'sku' => 'PULL-PREVIEW-SKU',
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111243',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $missingHandle = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111244',
        'sku' => 'PULL-PREVIEW-SKU',
        'barcode' => '9990007',
        'description' => 'Needs handle',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'handle' => null,
    ]);

    $hasHandle = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111245',
        'sku' => 'PULL-HAS-HANDLE',
        'barcode' => '9990008',
        'description' => 'Already has handle',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'handle' => 'stored-handle',
    ]);

    foreach ([$missingHandle, $hasHandle] as $product) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => (string) $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $res = $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/pull-handles/preview");

    $res->assertOk();
    $res->assertJsonPath('data.pull_count', 1);
    $res->assertJsonPath('data.already_has_handle_count', 1);
    $res->assertJsonPath('data.product_uuids.0', (string) $missingHandle->uuid);

    $products = collect($res->json('data.products'))->keyBy('sku');
    expect($products->has('PULL-PREVIEW-SKU'))->toBeTrue();
    expect($products->get('PULL-PREVIEW-SKU')['mirror_handle'] ?? null)->toBe('mirror-handle');
    expect($products->has('PULL-HAS-HANDLE'))->toBeFalse();
});

it('previews pull handles with zero pull count when all new products already have handles', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111246',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111247',
        'sku' => 'PULL-ALL-HAVE',
        'barcode' => '9990009',
        'description' => 'Has handle already',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'handle' => 'already-there',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'PULL-ALL-HAVE',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $res = $this->getJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/pull-handles/preview");

    $res->assertOk();
    $res->assertJsonPath('data.pull_count', 0);
    $res->assertJsonPath('data.already_has_handle_count', 1);
    expect($res->json('data.products'))->toBe([]);
});

it('blocks prepare inventory when a PO line is missing received qty', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111251',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111252',
        'sku' => 'INV-SKU',
        'barcode' => '9990005',
        'description' => 'Inventory product',
        'type' => 'Others',
        'vendor' => 'Plamod',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'INV-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 3,
        'qty_received' => null,
    ]);

    $service = app(PurchaseOrderWorkflowPrepareInventoryService::class);

    expect(fn () => $service->validateReceivedQuantities($po->uuid))
        ->toThrow(\App\Services\PurchaseOrders\Exceptions\PurchaseOrderWorkflowPrepareInventoryException::class);
});

it('auto-checks crawl step when new product has description and images', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111261',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111262',
        'sku' => 'CRAWL-SKU',
        'barcode' => '9990006',
        'description' => 'Crawl product',
        'type' => 'Others',
        'vendor' => 'Plamod',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'description_html' => '<p>Description</p>',
    ]);

    ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => 'hlj/images/test.jpg',
        'filename' => 'test.jpg',
        'shopify_enabled' => true,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'CRAWL-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-verify");

    $res->assertOk();
    $res->assertJsonPath('data.steps.crawl_desc_image_price.done', true);
});

it('does not auto-check manual image review step on verify', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111281',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111282',
        'sku' => 'IMG-REVIEW-SKU',
        'barcode' => '9990008',
        'description' => 'Image review product',
        'type' => 'Others',
        'vendor' => 'Plamod',
    ]);

    ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => 'hlj/images/test.jpg',
        'filename' => 'test.jpg',
        'shopify_enabled' => true,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'IMG-REVIEW-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-verify");

    $res->assertOk();
    $res->assertJsonPath('data.steps.select_and_arrange_product_images.done', false);
    $res->assertJsonPath('data.steps.select_and_arrange_product_images.checked', false);
    $res->assertJsonPath('data.steps.select_and_arrange_product_images.detail', 'manual');

    $this->patchJson("/api/v1/purchase-orders/{$po->uuid}/workflow-checklist", [
        'select_and_arrange_product_images' => true,
    ])->assertOk();

    $res2 = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-verify");
    $res2->assertJsonPath('data.steps.select_and_arrange_product_images.done', true);
    $res2->assertJsonPath('data.steps.select_and_arrange_product_images.checked', true);
    $res2->assertJsonPath('data.steps.select_and_arrange_product_images.newly_checked', false);
});

it('checks apply received workflow step after apply endpoint succeeds', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111271',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111272',
        'sku' => 'APPLY-SKU',
        'barcode' => '9990007',
        'description' => 'Apply product',
        'type' => 'Others',
        'vendor' => 'Plamod',
        'available_qty' => 1,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'APPLY-SKU',
        'vendor' => 'Plamod',
        'qty_ordered' => 2,
        'qty_received' => 3,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/apply-received-to-available");

    $res->assertOk();
    $res->assertJsonPath(
        'data.purchase_order.workflow_checklist.update_product_available_with_shopify_current_inventory_quantity',
        true,
    );

    $p->refresh();
    expect($p->available_qty)->toBe(4);
});
