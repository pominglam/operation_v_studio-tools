<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;

it('stores product_total and surcharge_total when importing a PO', function (): void {
    Product::query()->create([
        'sku' => 'PO-IMP-TOTALS-1',
        'barcode' => null,
        'description' => 'Import Totals Product',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'price' => '10.00',
        'available_qty' => 0,
    ]);

    $csv = implode("\n", [
        'SKU,Unit cost,Qty ordered,Qty shipped,Qty received',
        'PO-IMP-TOTALS-1,10.00,1,1,1',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('po.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'product_total' => 123.45,
        'surcharge_total' => 6.78,
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect((string) $po->product_total)->toBe('123.45');
    expect((string) $po->surcharge_total)->toBe('6.78');
});

