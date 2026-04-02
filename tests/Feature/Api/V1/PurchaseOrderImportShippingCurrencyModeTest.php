<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;

function dspiaeCsvForShippingCurrencyTests(): string
{
    return implode("\n", [
        'DSPIAE,,,,,,,,,,',
        'Total Weight/g,,,,,2100,Price/HKD,,,,1292.00',
        'Product,SKU,Product name,Barcode,Weight/g,Wholesale price,Recommended Retail Price,Each carton contain,Required Quantity / pcs (Carton Multiple),Total weight/g,Total Amount for Single',
        ',SHIP-HKD-1,Shipping currency item,6977151546258,105, HK$64.60 , HK$76.00 ,20,1,2100 ," HK$64.60 "',
        '',
    ]);
}

it('converts shipping_total from vendor currency to CAD when shipping_currency_mode=vendor', function (): void {
    $file = UploadedFile::fake()->createWithContent('dspiae.csv', dspiaeCsvForShippingCurrencyTests());

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'shipping_currency_mode' => 'vendor',
        'shipping_total' => 1000,
        'product_total' => 300,
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect((string) $po->vendor_currency_code)->toBe('HKD');
    expect((string) $po->fx_rate_to_cad)->toBe('0.232198');
    expect((string) $po->shipping_total)->toBe('232.20');
});

it('auto-detects and converts shipping_total to CAD when as-entered value is implausibly high for CAD', function (): void {
    $file = UploadedFile::fake()->createWithContent('dspiae.csv', dspiaeCsvForShippingCurrencyTests());

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'shipping_total' => 1000,
        'product_total' => 300,
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect((string) $po->shipping_total)->toBe('232.20');
});

it('returns validation error when shipping_currency_mode=vendor but fx cannot be derived', function (): void {
    $file = UploadedFile::fake()->createWithContent('dspiae.csv', dspiaeCsvForShippingCurrencyTests());

    $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'shipping_currency_mode' => 'vendor',
        'shipping_total' => 1000,
        // product_total omitted -> fx missing.
        'file' => $file,
    ])->assertStatus(422);
});

