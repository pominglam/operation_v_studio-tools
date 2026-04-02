<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;

it('persists a workflow checklist on a purchase order', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111111',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'notes' => null,
    ]);

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000111112',
        'sku' => 'SKU-1',
        'barcode' => '111',
        'description' => 'P1',
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 0,
    ]);

    // Ensure PO has items (realistic) but checklist is PO-level.
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'SKU-1',
        'vendor' => 'Plamod',
        'unit_cost' => null,
        'vendor_unit_cost' => null,
        'qty_ordered' => 1,
        'qty_shipped' => null,
        'qty_received' => null,
    ]);

    $res = $this->patchJson("/api/v1/purchase-orders/{$po->uuid}/workflow-checklist", [
        'import_po' => true,
        'export_to_shopify_get_handles' => true,
        'update_product_available_with_shopify_current_inventory_quantity' => true,
    ]);

    $res->assertOk();
    $res->assertJsonPath('data.id', $po->uuid);
    $res->assertJsonPath('data.workflow_checklist.import_po', true);
    $res->assertJsonPath('data.workflow_checklist.export_to_shopify_get_handles', true);
    $res->assertJsonPath('data.workflow_checklist.update_product_available_with_shopify_current_inventory_quantity', true);
    $res->assertJsonPath('data.workflow_checklist.set_selling_price', false);
    $res->assertJsonPath('data.workflow_checklist.ensure_all_products_have_barcode', false);

    $po->refresh();
    expect($po->workflow_checklist_json)->toBeArray();
    expect($po->workflow_checklist_json['import_po'] ?? null)->toBeTrue();
    expect($po->workflow_checklist_json['export_to_shopify_get_handles'] ?? null)->toBeTrue();
    expect($po->workflow_checklist_json['update_product_available_with_shopify_current_inventory_quantity'] ?? null)->toBeTrue();
    expect($po->workflow_checklist_json['ensure_all_products_have_barcode'] ?? null)->toBeFalse();
});

