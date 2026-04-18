<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;

it('auto-sanitizes non-utf8 CSV content instead of throwing a generic malformed utf-8 error', function (): void {
    // Intentionally include an invalid UTF-8 byte sequence in the product name.
    $badBytes = "Bad\xC3\x28Name";

    $csv = implode("\n", [
        'DSPIAE,,,,,,,,,,',
        'Total Weight/g,,,,,2100,Price/HKD,,,,1292.00',
        'Product,SKU,Product name,Barcode,Weight/g,Wholesale price,Recommended Retail Price,Each carton contain,Required Quantity / pcs (Carton Multiple),Total weight/g,Total Amount for Single',
        ',PT-ENC-1,"'.$badBytes.'",6977151546258,105, HK$64.60 , HK$76.00 ,20,1,2100 ," HK$64.60 "',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae-bad-enc.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
});
