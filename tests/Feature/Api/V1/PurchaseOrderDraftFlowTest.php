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
        'shipment_method' => 'air',
        'available_qty' => 3,
        'maintain_qty' => 10,
        'latest_unit_cost' => '10.00',
        'latest_landed_unit_cost' => '12.34',
    ]);
    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000220002',
        'sku' => 'DR-CREATE-2',
        'description' => 'Draft Create 2',
        'vendor' => 'Stedi',
        'shipment_method' => 'air',
        'available_qty' => 7,
        'maintain_qty' => 5,
        'latest_unit_cost' => '4.56',
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
    expect($po->shipment_method)->toBe('air');
    expect($po->product_total)->toBe('30.00');
    expect($po->shipping_total)->toBe('7.02');

    $items = PurchaseOrderItem::query()
        ->where('purchase_order_id', $po->id)
        ->orderBy('sku')
        ->get()
        ->keyBy('sku');

    expect((int) ($items['DR-CREATE-1']->qty_ordered ?? -1))->toBe(3);
    expect((float) $items['DR-CREATE-1']->unit_cost)->toBe(10.0);
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

it('exports draft PO lines as csv with HKD product cost for Stedi', function (): void {
    $product = Product::query()->create([
        'sku' => 'DR-CSV-1',
        'barcode' => '999111',
        'description' => 'Draft CSV Product',
        'vendor' => 'Stedi',
    ]);
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000220099',
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'CAD',
        'fx_rate_to_cad' => '0.172881',
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Stedi',
        'vendor_unit_cost' => '88.50',
        'unit_cost' => '15.30',
        'qty_ordered' => 8,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'DR-CSV-ZERO',
        'vendor' => 'Stedi',
        'vendor_unit_cost' => '10.00',
        'qty_ordered' => 0,
    ]);

    $res = $this->get("/api/v1/purchase-orders/{$po->uuid}/draft-lines-export");
    $res->assertOk();
    $csv = (string) $res->streamedContent();
    expect($csv)->toContain(
        'SKU,"Product Name",Qty,"Product cost unit (CAD)","Product cost line (CAD)","Product cost unit (HKD)","Product cost line (HKD)"',
    );
    expect($csv)->toContain('DR-CSV-1,"Draft CSV Product",8,15.30,122.40,88.50,708.00');
    expect($csv)->not->toContain('DR-CSV-ZERO');
    expect($csv)->not->toContain('Barcode');
});

it('derives CAD product cost from prior PO fx when current PO has no fx', function (): void {
    $product = Product::query()->create([
        'sku' => 'DR-CSV-FX',
        'description' => 'FX fallback product',
        'vendor' => 'Stedi',
    ]);
    PurchaseOrder::query()->create([
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'HKD',
        'product_total' => '100.00',
        'vendor_product_total' => '500.00',
        'fx_rate_to_cad' => '0.200000',
        'created_at' => '2026-01-01 10:00:00',
    ]);
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000220097',
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'CAD',
        'fx_rate_to_cad' => null,
        'created_at' => '2026-05-01 10:00:00',
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Stedi',
        'vendor_unit_cost' => '10.00',
        'qty_ordered' => 3,
    ]);

    $res = $this->get("/api/v1/purchase-orders/{$po->uuid}/draft-lines-export");
    $res->assertOk();
    $csv = (string) $res->streamedContent();
    expect($csv)->toContain('DR-CSV-FX,"FX fallback product",3,2.00,6.00,10.00,30.00');
});

it('exports draft PO lines with CAD product cost for other vendors', function (): void {
    $product = Product::query()->create([
        'sku' => 'DR-CSV-PL',
        'description' => 'Plamod line',
        'vendor' => 'Plamod',
    ]);
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000220098',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '12.50',
        'qty_ordered' => 4,
    ]);

    $res = $this->get("/api/v1/purchase-orders/{$po->uuid}/draft-lines-export");
    $res->assertOk();
    $csv = (string) $res->streamedContent();
    expect($csv)->toContain('"Product cost unit (CAD)","Product cost line (CAD)"');
    expect($csv)->toContain('DR-CSV-PL,"Plamod line",4,12.50,50.00');
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
