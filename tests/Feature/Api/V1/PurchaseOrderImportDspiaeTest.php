<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use Illuminate\Http\UploadedFile;

it('imports a DSPIAE CSV (HKD) and stores vendor currency + vendor product total', function (): void {
    $csv = implode("\n", [
        'DSPIAE,,,,,,,,,,',
        'Total Weight/g,,,,,2100,Price/HKD,,,,1292.00',
        'Product,SKU,Product name,Barcode,Weight/g,Wholesale price,Recommended Retail Price,Each carton contain,Required Quantity / pcs (Carton Multiple),Total weight/g,Total Amount for Single',
        ',PT-AB,Wash-Free airbrush,6977151546258,105, HK$64.60 , HK$76.00 ,20,20,2100 ," HK$1,292.00 "',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect($po->vendor)->toBe('Dspiae');
    expect($po->vendor_currency_code)->toBe('HKD');
    expect((string) $po->vendor_product_total)->toBe('1292.00');

    $product = Product::query()->where('sku', 'PT-AB')->firstOrFail();
    expect((string) $product->vendor)->toBe('Dspiae');
    expect((string) $product->barcode)->toBe('6977151546258');
    expect((string) $product->description)->toBe('Wash-Free airbrush');

    /** @var PurchaseOrderItem $item */
    $item = PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->firstOrFail();
    expect($item->sku)->toBe('PT-AB');
    expect((string) $item->vendor_unit_cost)->toBe('64.6000');
    expect($item->unit_cost)->toBeNull();
    expect($item->qty_ordered)->toBe(20);
    expect($item->qty_shipped)->toBeNull();
    expect($item->qty_received)->toBeNull();
});

