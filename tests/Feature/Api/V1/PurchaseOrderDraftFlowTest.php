<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductSellingPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('creates a draft PO from selected products', function (): void {
    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000220001',
        'sku' => 'DR-CREATE-1',
        'description' => 'Draft Create 1',
        'vendor' => 'Stedi',
        'available_qty' => 3,
        'maintain_qty' => 10,
        'latest_landed_unit_cost' => '12.34',
    ]);
    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000220002',
        'sku' => 'DR-CREATE-2',
        'description' => 'Draft Create 2',
        'vendor' => 'Stedi',
        'available_qty' => 7,
        'maintain_qty' => 5,
        'latest_landed_unit_cost' => '4.56',
    ]);

    $openPo = PurchaseOrder::query()->create([
        'vendor' => 'Stedi',
        'received_date' => null,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $openPo->id,
        'product_id' => $p1->id,
        'sku' => $p1->sku,
        'vendor' => 'Stedi',
        'unit_cost' => '1.00',
        'qty_ordered' => 4,
    ]);

    $res = $this->postJson('/api/v1/purchase-orders/drafts/create-from-products', [
        'ids' => [$p1->uuid, $p2->uuid],
    ]);

    $res->assertOk();
    $poUuid = (string) $res->json('purchase_order_uuid');
    expect($poUuid)->not->toBe('');
    $res->assertJsonPath('added', 2);

    $po = PurchaseOrder::query()->where('uuid', $poUuid)->firstOrFail();
    expect($po->vendor)->toBe('Stedi');

    $items = PurchaseOrderItem::query()
        ->where('purchase_order_id', $po->id)
        ->orderBy('sku')
        ->get()
        ->keyBy('sku');

    expect((int) ($items['DR-CREATE-1']->qty_ordered ?? -1))->toBe(3);
    expect((int) ($items['DR-CREATE-2']->qty_ordered ?? -1))->toBe(0);
});

it('adds products by SKU to draft PO and skips existing, mismatch, and missing rows', function (): void {
    $po = PurchaseOrder::query()->create(['vendor' => 'Stedi']);

    $existing = Product::query()->create([
        'sku' => 'DR-ADD-EXISTING',
        'description' => 'Existing',
        'vendor' => 'Stedi',
        'available_qty' => 0,
        'maintain_qty' => 1,
    ]);
    $new = Product::query()->create([
        'sku' => 'DR-ADD-NEW',
        'description' => 'New',
        'vendor' => 'Stedi',
        'available_qty' => 0,
        'maintain_qty' => 2,
    ]);
    $mismatch = Product::query()->create([
        'sku' => 'DR-ADD-MISMATCH',
        'description' => 'Mismatch',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 2,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $existing->id,
        'sku' => $existing->sku,
        'vendor' => 'Stedi',
        'unit_cost' => null,
        'qty_ordered' => 1,
    ]);

    $res = $this->postJson("/api/v1/purchase-orders/{$po->uuid}/draft-products", [
        'skus' => ['DR-ADD-EXISTING', 'DR-ADD-NEW', 'DR-ADD-MISMATCH', 'DR-ADD-MISSING'],
    ]);

    $res->assertOk();
    $res->assertJsonPath('added', 1);
    $res->assertJsonPath('skipped_existing', 1);
    $res->assertJsonPath('skipped_vendor_mismatch', 1);
    $res->assertJsonPath('skipped_not_found', 1);

    $this->assertDatabaseHas('purchase_order_items', [
        'purchase_order_id' => $po->id,
        'sku' => 'DR-ADD-NEW',
    ]);
});

it('exports draft PO lines as csv', function (): void {
    $product = Product::query()->create([
        'sku' => 'DR-CSV-1',
        'barcode' => '999111',
        'description' => 'Draft CSV Product',
        'vendor' => 'Stedi',
    ]);
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000220099',
        'vendor' => 'Stedi',
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Stedi',
        'qty_ordered' => 8,
    ]);

    $res = $this->get("/api/v1/purchase-orders/{$po->uuid}/draft-lines-export");
    $res->assertOk();
    $csv = (string) $res->streamedContent();
    expect($csv)->toContain('SKU,"Product Name",Barcode,"Qty Ordered"');
    expect($csv)->toContain('DR-CSV-1');
    expect($csv)->toContain('Draft CSV Product');
});

it('returns status and draft metrics on purchase order detail items', function (): void {
    $product = Product::query()->create([
        'sku' => 'DR-METRICS-1',
        'description' => 'Metrics Product',
        'vendor' => 'Stedi',
        'available_qty' => 6,
        'maintain_qty' => 15,
        'latest_landed_unit_cost' => '5.50',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'selling_price' => '11.00',
    ]);

    $openPo = PurchaseOrder::query()->create(['vendor' => 'Stedi']);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $openPo->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Stedi',
        'qty_ordered' => 4,
    ]);
    $receivedPo = PurchaseOrder::query()->create([
        'vendor' => 'Stedi',
        'received_date' => '2026-02-01',
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $receivedPo->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Stedi',
        'qty_received' => 20,
    ]);

    $draftPo = PurchaseOrder::query()->create([
        'vendor' => 'Stedi',
        'ordered_date' => null,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $draftPo->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Stedi',
        'qty_ordered' => 2,
    ]);

    $res = $this->getJson("/api/v1/purchase-orders/{$draftPo->uuid}");
    $res->assertOk();
    $res->assertJsonPath('data.status', 'draft');
    $res->assertJsonPath('data.items.0.available', 6);
    $res->assertJsonPath('data.items.0.maintain', 15);
    $res->assertJsonPath('data.items.0.not_arrived', 6);
    $res->assertJsonPath('data.items.0.reorder', 3);
    $res->assertJsonPath('data.items.0.total_ordered', 20);
    $res->assertJsonPath('data.items.0.total_sold', 14);
    $res->assertJsonPath('data.items.0.selling_price', '11.00');
    $res->assertJsonPath('data.items.0.latest_landed_unit_cost', '5.50');
    $res->assertJsonPath('data.items.0.multiplier', '2.00');
});
