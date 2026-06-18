<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;

it('merges duplicate skus within a plamod import file into one line', function (): void {
    $csv = implode("\n", [
        'Order ID,SKU,Barcode,Product Name,Qty Ordered,Qty Filled,Unit Price,Tariff Rate (%),Tariff Amount,Line Subtotal (Before Tax),Tax Rate (%),Tax Amount,Line Total (After Tax),Order Type',
        '16863003,5058777,4573102558978,Duplicate Product,2,2,26.51,0.00,0.00,53.02,5.00,0.00,0.00,Regular',
        '16863004,5058777,4573102558978,Duplicate Product,2,2,25.73,0.00,0.00,51.46,5.00,0.00,0.00,Regular',
        '16863003,5062042,4573102640949,Other Product,2,2,10.20,0.00,0.00,20.40,5.00,0.00,0.00,Regular',
    ]);

    $file = UploadedFile::fake()->createWithContent('plamod-dupes.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();

    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->count())->toBe(2);

    /** @var PurchaseOrderItem $merged */
    $merged = PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->where('sku', '5058777')->firstOrFail();
    expect($merged->qty_ordered)->toBe(4);
    expect($merged->qty_received)->toBe(4);
    expect((string) $merged->unit_cost)->toBe('26.1200');
});

it('merges append import rows into an existing purchase order line by product', function (): void {
    $firstCsv = implode("\n", [
        'SKU,Unit cost,Qty ordered,Qty shipped,Qty received',
        'APPEND-MERGE-SKU,10.00,6,6,6',
    ]);
    $firstFile = UploadedFile::fake()->createWithContent('append-first.csv', $firstCsv, 'text/csv');

    $first = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'file' => $firstFile,
    ])->assertOk();

    $uuid = (string) ($first->json('purchase_order_uuid') ?? '');

    $secondCsv = implode("\n", [
        'SKU,Unit cost,Qty ordered,Qty shipped,Qty received',
        'APPEND-MERGE-SKU,10.00,1,1,1',
    ]);
    $secondFile = UploadedFile::fake()->createWithContent('append-second.csv', $secondCsv, 'text/csv');

    $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'purchase_order_uuid' => $uuid,
        'import_mode' => 'append',
        'file' => $secondFile,
    ])->assertOk();

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();

    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->count())->toBe(1);

    /** @var PurchaseOrderItem $item */
    $item = PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->where('sku', 'APPEND-MERGE-SKU')->firstOrFail();
    expect($item->qty_ordered)->toBe(7);
    expect($item->qty_received)->toBe(7);
    expect((string) $item->unit_cost)->toBe('10.0000');
});
