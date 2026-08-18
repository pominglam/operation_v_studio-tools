<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;

it('sets product and line vendor from PO import vendor when product had a different vendor', function (): void {
    Product::query()->create([
        'sku' => '5068706',
        'barcode' => '4573102687067',
        'description' => 'ENTRY GRADE 1/144 WING GUNDAM',
        'type' => 'HG',
        'vendor' => 'Stedi',
    ]);

    $csv = implode("\n", [
        'Order ID,SKU,Barcode,Product Name,Qty Ordered,Qty Filled,Unit Price,Tariff Rate (%),Tariff Amount,Line Subtotal (Before Tax),Tax Rate (%),Tax Amount,Line Total (After Tax),Order Type',
        '16863037,5068706,4573102687067,ENTRY GRADE 1/144 WING GUNDAM,1,1,10.00,0.00,0.00,10.00,5.00,0.50,10.50,Regular',
    ]);

    $file = UploadedFile::fake()->createWithContent('plamod-order-details.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'received_date' => '2026-07-06',
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    $product = Product::query()->where('sku', '5068706')->firstOrFail();
    expect((string) $product->vendor)->toBe('Plamod');

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect($po->vendor)->toBe('Plamod');

    /** @var PurchaseOrderItem $item */
    $item = PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->where('sku', '5068706')->firstOrFail();
    expect((string) $item->vendor)->toBe('Plamod');
});
