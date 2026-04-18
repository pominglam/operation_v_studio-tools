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
