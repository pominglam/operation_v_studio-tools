<?php

declare(strict_types=1);

use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;

it('converts DSPIAE vendor unit costs to CAD at import time when product_total and vendor_product_total are provided (fx known)', function (): void {
    $csv = implode("\n", [
        'DSPIAE,,,,,,,,,,',
        'Total Weight/g,,,,,2100,Price/HKD,,,,1292.00',
        'Product,SKU,Product name,Barcode,Weight/g,Wholesale price,Recommended Retail Price,Each carton contain,Required Quantity / pcs (Carton Multiple),Total weight/g,Total Amount for Single',
        ',PT-AB,Wash-Free airbrush,6977151546258,105, HK$64.60 , HK$76.00 ,20,1,2100 ," HK$64.60 "',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'product_total' => 300,
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    $item = PurchaseOrderItem::query()
        ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
        ->where('purchase_orders.uuid', '=', $uuid)
        ->select('purchase_order_items.*')
        ->firstOrFail();

    expect((string) $item->vendor_unit_cost)->toBe('64.6000');
    // FX uses PO totals: 300 / 1292 = 0.232198...; 64.60 * (300/1292) = 15.0000 exactly.
    expect((string) $item->unit_cost)->toBe('15.0000');
});
