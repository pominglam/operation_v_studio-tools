<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;

it('appends more products into an existing PO when import_mode=append', function (): void {
    $csvFirst = implode("\n", [
        'SKU,Unit cost,Qty ordered,Qty shipped,Qty received',
        'APPEND-PO-SKU-1,10.00,1,1,1',
    ]);

    $firstFile = UploadedFile::fake()->createWithContent('po-first.csv', $csvFirst, 'text/csv');
    $first = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'shipping_total' => '4.00',
        'file' => $firstFile,
    ])->assertOk();

    $uuid = (string) ($first->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');
    expect((string) ($first->json('shipping_per_unit') ?? ''))->toBe('4.000000');

    $csvSecond = implode("\n", [
        'SKU,Unit cost,Qty ordered,Qty shipped,Qty received',
        'APPEND-PO-SKU-2,20.00,2,2,2',
    ]);
    $secondFile = UploadedFile::fake()->createWithContent('po-second.csv', $csvSecond, 'text/csv');

    $second = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'purchase_order_uuid' => $uuid,
        'import_mode' => 'append',
        'file' => $secondFile,
    ])->assertOk();

    expect((string) ($second->json('purchase_order_uuid') ?? ''))->toBe($uuid);
    expect((string) ($second->json('shipping_per_unit') ?? ''))->toBe('1.333333'); // 4 / (1 + 2)

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();

    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->count())->toBe(2);
    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->where('sku', 'APPEND-PO-SKU-1')->exists())->toBeTrue();
    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->where('sku', 'APPEND-PO-SKU-2')->exists())->toBeTrue();
    expect((string) $po->product_total)->toBe('50.00'); // (10*1) + (20*2)
    expect((string) $po->shipping_total)->toBe('4.00');

    $itemIds = PurchaseOrderItem::query()
        ->where('purchase_order_id', $po->id)
        ->pluck('id')
        ->all();

    expect(InventoryLot::query()->whereIn('purchase_order_item_id', $itemIds)->count())->toBe(2);
    expect(
        InventoryLot::query()
            ->whereIn('purchase_order_item_id', $itemIds)
            ->where('shipping_per_unit', '1.333333')
            ->count()
    )->toBe(2);
});

it('combines PO header totals when import_mode=append', function (): void {
    $csvFirst = implode("\n", [
        'SKU,Unit cost,Qty ordered,Qty shipped,Qty received',
        'APPEND-COMBINE-1,10.00,1,0,0',
    ]);

    $firstFile = UploadedFile::fake()->createWithContent('po-combine-first.csv', $csvFirst, 'text/csv');
    $first = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'product_total' => '10.00',
        'shipping_total' => '4.00',
        'file' => $firstFile,
    ])->assertOk();

    $uuid = (string) ($first->json('purchase_order_uuid') ?? '');

    $csvSecond = implode("\n", [
        'SKU,Unit cost,Qty ordered,Qty shipped,Qty received',
        'APPEND-COMBINE-2,20.00,2,0,0',
    ]);
    $secondFile = UploadedFile::fake()->createWithContent('po-combine-second.csv', $csvSecond, 'text/csv');

    $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'purchase_order_uuid' => $uuid,
        'import_mode' => 'append',
        'product_total' => '40.00',
        'shipping_total' => '6.00',
        'file' => $secondFile,
    ])->assertOk();

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();

    expect((string) $po->product_total)->toBe('50.00');
    expect((string) $po->shipping_total)->toBe('10.00');
});

it('combines HKD vendor and CAD shipping totals on PM invoice append', function (): void {
    $firstCsv = implode("\n", [
        'Customer,Item,SKU,Qty,unit price,Amount',
        'PM,Tool A,PM-APP-A,1,100.00,100.00',
        ',,,,Air shipping service with local tracking,50.00',
        '',
    ]);
    $firstFile = UploadedFile::fake()->createWithContent('pm-append-1.csv', $firstCsv);

    $first = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'product_total' => '20.00',
        'product_total_includes_fees' => true,
        'file' => $firstFile,
    ])->assertOk();

    $uuid = (string) ($first->json('purchase_order_uuid') ?? '');

    /** @var PurchaseOrder $poAfterFirst */
    $poAfterFirst = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect((string) $poAfterFirst->vendor_product_total)->toBe('100.00');
    $firstShipping = (string) $poAfterFirst->shipping_total;
    expect($firstShipping)->not()->toBe('');

    $secondCsv = implode("\n", [
        'Customer,Item,SKU,Qty,unit price,Amount',
        'PM,Tool B,PM-APP-B,2,60.00,120.00',
        ',,,,Air shipping service with local tracking,30.00',
        '',
    ]);
    $secondFile = UploadedFile::fake()->createWithContent('pm-append-2.csv', $secondCsv);

    $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'purchase_order_uuid' => $uuid,
        'import_mode' => 'append',
        'product_total' => '15.00',
        'product_total_includes_fees' => true,
        'file' => $secondFile,
    ])->assertOk();

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();

    expect((string) $po->vendor_product_total)->toBe('220.00');
    expect((float) $po->shipping_total)->toBeGreaterThan((float) $firstShipping);
    expect($po->fx_rate_to_cad)->not()->toBeNull();
    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->count())->toBe(2);
});

it('rejects invalid import_mode for purchase order import', function (): void {
    $csv = implode("\n", [
        'SKU,Unit cost,Qty ordered,Qty shipped,Qty received',
        'APPEND-PO-BAD-MODE,10.00,1,1,1',
    ]);
    $file = UploadedFile::fake()->createWithContent('po-invalid-mode.csv', $csv, 'text/csv');

    $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'import_mode' => 'invalid',
        'file' => $file,
    ])->assertStatus(422);
});
