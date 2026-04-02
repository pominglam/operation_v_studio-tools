<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('returns replenishment preview rows using maintain available and not-arrived qty', function (): void {
    $poOpen = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130001',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
    ]);
    $poArrived = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130002',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-01-01',
    ]);

    $include = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130011',
        'sku' => 'REP-1',
        'description' => 'Replenish me',
        'vendor' => 'Plamod',
        'available_qty' => 2,
        'maintain_qty' => 10,
    ]);
    $enoughStock = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130012',
        'sku' => 'REP-2',
        'description' => 'Enough stock',
        'vendor' => 'Plamod',
        'available_qty' => 10,
        'maintain_qty' => 10,
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130013',
        'sku' => 'REP-ARCH',
        'description' => 'Archived product',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 5,
        'archived_at' => now(),
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poOpen->id,
        'product_id' => $include->id,
        'sku' => 'REP-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 5,
        'qty_received' => 2,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poArrived->id,
        'product_id' => $include->id,
        'sku' => 'REP-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 9,
        'qty_received' => 0,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poOpen->id,
        'product_id' => $enoughStock->id,
        'sku' => 'REP-2',
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
        'qty_received' => 0,
    ]);

    $res = $this->getJson('/api/v1/products/replenishment/preview');
    $res->assertOk();
    $res->assertJsonPath('ok', true);
    $res->assertJsonPath('meta.count', 1);
    $res->assertJsonPath('meta.total_suggested_order_qty', 3);
    $res->assertJsonPath('data.0.sku', 'REP-1');
    $res->assertJsonPath('data.0.available_qty', 2);
    $res->assertJsonPath('data.0.maintain_qty', 10);
    $res->assertJsonPath('data.0.inbound_open_po_qty', 5);
    $res->assertJsonPath('data.0.suggested_order_qty', 3);
});

it('exports replenishment csv with expected columns and rows', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130021',
        'sku' => 'REP-CSV',
        'description' => 'CSV product',
        'barcode' => '123456',
        'vendor' => 'Plamod',
        'available_qty' => 1,
        'maintain_qty' => 4,
    ]);

    $res = $this->get('/api/v1/products/replenishment/export');
    $res->assertOk();

    $csv = $res->streamedContent();
    $fh = fopen('php://temp', 'w+b');
    expect($fh)->not->toBeFalse();
    fwrite($fh, $csv);
    rewind($fh);

    $header = fgetcsv($fh);
    $header[0] = ltrim((string) ($header[0] ?? ''), "\xEF\xBB\xBF");
    expect($header)->toBe([
        'SKU',
        'Product Name',
        'Barcode',
        'Available Qty',
        'Maintain Qty',
        'Inbound Qty (Open POs)',
        'Suggested Order Qty',
    ]);

    $row = fgetcsv($fh);
    fclose($fh);

    expect($row)->toBe([
        'REP-CSV',
        'CSV product',
        '123456',
        '1',
        '4',
        '0',
        '3',
    ]);
});

it('includes not-arrived and reorder columns on products list rows', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130031',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
    ]);
    $poArrived = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130033',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-01-10',
    ]);
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000130032',
        'sku' => 'REP-LIST',
        'description' => 'List product',
        'vendor' => 'Plamod',
        'available_qty' => 2,
        'maintain_qty' => 9,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $p->id,
        'sku' => 'REP-LIST',
        'vendor' => 'Plamod',
        'qty_ordered' => 8,
        'qty_received' => 3,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poArrived->id,
        'product_id' => $p->id,
        'sku' => 'REP-LIST',
        'vendor' => 'Plamod',
        'qty_ordered' => 4,
        'qty_received' => 2,
    ]);

    $res = $this->getJson('/api/v1/products?search=REP-LIST');
    $res->assertOk();
    $res->assertJsonPath('data.0.total_ordered', 2);
    $res->assertJsonPath('data.0.not_arrived', 8);
    $res->assertJsonPath('data.0.reorder', 0);
});

