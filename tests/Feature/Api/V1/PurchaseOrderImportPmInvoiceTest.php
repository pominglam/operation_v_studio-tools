<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;

it('imports a PM broker invoice CSV for Stedi vendor with HKD line prices', function (): void {
    $csv = implode("\n", [
        'Invoice,,,,,',
        'Customer,Item,SKU,Qty,unit price,Amount',
        'PM,Stedi tool MS-001,MS-001,2,45.50,91.00',
        'PM,Stedi tool MS-002,MS-002,1,120.00,120.00',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('pm-invoice.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Stedi',
        'product_total' => '35.00',
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect($po->vendor)->toBe('Stedi');
    expect($po->vendor_currency_code)->toBe('HKD');
    expect((string) $po->vendor_product_total)->toBe('211.00');
    expect((string) $po->product_total)->toBe('35.00');
    expect($po->fx_rate_to_cad)->not()->toBeNull();

    $product = Product::query()->where('sku', 'MS-001')->firstOrFail();
    expect((string) $product->description)->toBe('Stedi tool MS-001');
});

it('previews a PM broker invoice CSV with HKD and CAD columns', function (): void {
    $csv = implode("\n", [
        'Customer,Item,SKU,Qty,unit price,Amount',
        'PM,Stedi tool MS-001,MS-001,2,45.50,91.00',
        'PM,Stedi tool MS-002,MS-002,1,120.00,120.00',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('pm-invoice.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import/preview', [
        'vendor' => 'Dspiae',
        'product_total' => '35.00',
        'shipping_total' => '5.00',
        'file' => $file,
    ])->assertOk();

    expect($res->json('format'))->toBe('pm_invoice');
    expect($res->json('vendor_currency_code'))->toBe('HKD');
    expect($res->json('vendor_product_total_hkd'))->toBe('211.00');
    expect($res->json('product_total_cad'))->toBe('35.00');
    expect($res->json('shipping_total_cad'))->toBe('5.00');
    expect($res->json('fx_rate_to_cad'))->not()->toBeNull();
    expect($res->json('lines'))->toHaveCount(2);
    expect($res->json('totals.qty'))->toBe(3);
    expect($res->json('totals.line_total_hkd'))->toBe('211.00');
    expect($res->json('lines.0.item'))->toBe('Stedi tool MS-001');
    expect($res->json('lines.0.sku'))->toBe('MS-001');
});

it('previews a PM broker invoice with footer freight row layout', function (): void {
    $csv = implode("\n", [
        'Customer,Item,SKU,Qty,unit price,Amount',
        'PM,Stedi tool MS-001,MS-001,2,45.50,91.00',
        ',,,334,,"HK$6,092.44"',
        ',,,,Air shipping service with local tracking,"HK$1,950.00"',
        'Shipping Address:,Po Ming,,,Final Amount:,"HK$8,042.44"',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('pm-invoice-footer.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import/preview', [
        'vendor' => 'Stedi',
        'product_total' => '100.00',
        'product_total_includes_fees' => true,
        'file' => $file,
    ])->assertOk();

    expect($res->json('vendor_freight_total_hkd'))->toBe('1950.00');
    expect($res->json('vendor_product_total_hkd'))->toBe('91.00');
    expect($res->json('total_paid_cad'))->toBe('100.00');
    expect($res->json('product_total_cad'))->toBe('4.46');
    expect($res->json('shipping_total_cad'))->toBe('95.54');
});

it('previews a PM broker invoice with all-fees CAD split by product vs freight HKD', function (): void {
    $csv = implode("\n", [
        'Customer,Item,SKU,Qty,unit price,Amount',
        'PM,Stedi tool MS-001,MS-001,2,45.50,91.00',
        'PM,Stedi tool MS-002,MS-002,1,120.00,120.00',
        'PM,Air shipping service with local tracking,,1,1950.00,1950.00',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('pm-invoice.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import/preview', [
        'vendor' => 'Stedi',
        'product_total' => '100.00',
        'product_total_includes_fees' => true,
        'file' => $file,
    ])->assertOk();

    expect($res->json('product_total_includes_fees'))->toBeTrue();
    expect($res->json('total_paid_cad'))->toBe('100.00');
    expect($res->json('vendor_product_total_hkd'))->toBe('211.00');
    expect($res->json('vendor_freight_total_hkd'))->toBe('1950.00');
    expect($res->json('product_total_cad'))->toBe('9.76');
    expect($res->json('shipping_total_cad'))->toBe('90.24');
    expect($res->json('fx_rate_to_cad'))->toBe('0.046256');
    expect($res->json('lines.0.unit_price_cad'))->toBe('2.10');
});

it('imports a PM broker invoice when product total includes all fees', function (): void {
    $csv = implode("\n", [
        'Customer,Item,SKU,Qty,unit price,Amount',
        'PM,Stedi tool MS-001,MS-001,2,45.50,91.00',
        'PM,Air shipping service with local tracking,,1,50.00,50.00',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('pm-invoice-fees.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Stedi',
        'product_total' => '50.00',
        'product_total_includes_fees' => true,
        'file' => $file,
    ])->assertOk();

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', (string) $res->json('purchase_order_uuid'))->firstOrFail();
    expect((string) $po->product_total)->toBe('32.27');
    expect((string) $po->shipping_total)->toBe('17.73');
    expect((string) $po->vendor_product_total)->toBe('91.00');
});

it('falls back to native Dspiae CSV when PM columns are absent', function (): void {
    $csv = implode("\n", [
        'DSPIAE,,,,,,,,,,',
        'Total Weight/g,,,,,2100,Price/HKD,,,,1292.00',
        'Product,SKU,Product name,Barcode,Weight/g,Wholesale price,Recommended Retail Price,Each carton contain,Required Quantity / pcs (Carton Multiple),Total weight/g,Total Amount for Single',
        ',PT-AB,Wash-Free airbrush,6977151546258,105, HK$64.60 , HK$76.00 ,20,20,2100 ," HK$1,292.00 "',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import/preview', [
        'vendor' => 'Dspiae',
        'file' => $file,
    ])->assertOk();

    expect($res->json('format'))->toBe('dspiae');
    expect($res->json('lines.0.sku'))->toBe('PT-AB');
});
