<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;

it('imports a Plamod order-details CSV and stops before SUMMARY rows', function (): void {
    $csv = implode("\n", [
        'Order ID,SKU,Barcode,Product Name,Qty Ordered,Qty Filled,Unit Price,Tariff Rate (%),Tariff Amount,Line Subtotal (Before Tax),Tax Rate (%),Tax Amount,Line Total (After Tax),Order Type',
        '16863003,5055897,4573102558978,Orphans HG 1/144 Reginlaze Julia,2,2,16.31,0.00,0.00,32.62,5.00,1.63,34.25,Regular',
        '16863003,5057434,4573102574343,KERORO - GIRORO ROBO,4,4,9.17,0.00,0.00,36.68,5.00,1.83,38.51,Preorder',
        '16863003,5064094,4573102640949,MG MSN-02 Zeong,5,1,61.47,0.00,0.00,61.47,5.00,3.07,64.54,Regular',
        '',
        'SUMMARY',
        'Order Date,"January 5, 2026 at 08:44 PM"',
        'TOTALS',
        'Total Qty Ordered,7',
        'Total Qty Filled,3',
        'Shipping Cost,10.00',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('plamod-order-details.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'ordered_date' => '2026-01-05',
        'shipped_date' => '2026-01-13',
        'received_date' => '2026-01-21',
        'shipping_total' => 10.00,
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');
    expect((string) $res->json('shipping_per_unit'))->toBe('1.428571'); // 10 / (2 + 4 + 1)

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect($po->vendor)->toBe('Plamod');
    expect((string) $po->shipping_total)->toBe('10.00');

    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->count())->toBe(3);
    /** @var PurchaseOrderItem $preorder */
    $preorder = PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->where('sku', '5057434')->firstOrFail();
    expect((string) $preorder->unit_cost)->toBe('9.1700');
    expect($preorder->qty_ordered)->toBe(4);
    expect($preorder->qty_received)->toBe(4);

    /** @var PurchaseOrderItem $item1 */
    $item1 = PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->where('sku', '5055897')->firstOrFail();
    expect((string) $item1->unit_cost)->toBe('16.3100');
    expect($item1->qty_ordered)->toBe(2);
    expect($item1->qty_received)->toBe(2);

    /** @var PurchaseOrderItem $item2 */
    $item2 = PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->where('sku', '5064094')->firstOrFail();
    expect((string) $item2->unit_cost)->toBe('61.4700');
    expect($item2->qty_ordered)->toBe(5);
    expect($item2->qty_received)->toBe(1);

    $p1 = Product::query()->where('sku', '5055897')->firstOrFail();
    expect((string) $p1->barcode)->toBe('4573102558978');
    expect((string) $p1->description)->toBe('Orphans HG 1/144 Reginlaze Julia');

    $p2 = Product::query()->where('sku', '5064094')->firstOrFail();
    expect((string) $p2->barcode)->toBe('4573102640949');
    expect((string) $p2->description)->toBe('MG MSN-02 Zeong');

    expect(InventoryLot::query()->whereIn('purchase_order_item_id', [$item1->id, $preorder->id, $item2->id])->count())->toBe(3);
    $lot = InventoryLot::query()->where('purchase_order_item_id', $item1->id)->firstOrFail();
    expect((string) $lot->shipping_per_unit)->toBe('1.428571');
});
