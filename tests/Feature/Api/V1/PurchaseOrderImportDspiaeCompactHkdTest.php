<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;

it('imports compact DSPIAE HKD csv format (SKU, Qty, Unit Price (HKD), Amount (HKD))', function (): void {
    $csv = implode("\n", [
        'SKU,Qty,Unit Price (HKD),Amount (HKD)',
        'MP-20,5,13.21,66.03',
        'PT-AB,6,64.6,387.6',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae-compact.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');
    expect((int) ($res->json('items') ?? 0))->toBe(2);

    $po = PurchaseOrder::query()->where('uuid', '=', $uuid)->firstOrFail();
    expect((string) $po->vendor_currency_code)->toBe('HKD');

    $items = PurchaseOrderItem::query()
        ->where('purchase_order_id', '=', (int) $po->id)
        ->orderBy('id')
        ->get();
    expect($items)->toHaveCount(2);
    expect((string) $items[0]->sku)->toBe('MP-20');
    expect((int) ($items[0]->qty_ordered ?? 0))->toBe(5);
    expect((string) ($items[0]->vendor_unit_cost ?? ''))->toBe('13.2100');
    expect($items[0]->unit_cost)->toBeNull();
    expect((string) $items[1]->sku)->toBe('PT-AB');
    expect((int) ($items[1]->qty_ordered ?? 0))->toBe(6);
    expect((string) ($items[1]->vendor_unit_cost ?? ''))->toBe('64.6000');
});

it('keeps product_total non-zero for compact DSPIAE HKD import when user provides product_total', function (): void {
    $csv = implode("\n", [
        'SKU,Qty,Unit Price (HKD),Amount (HKD)',
        'MP-20,5,13.21,66.03',
        'PT-AB,6,64.6,387.6',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae-compact.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'product_total' => 871.54,
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    $po = PurchaseOrder::query()->where('uuid', '=', $uuid)->firstOrFail();
    expect((string) $po->vendor_currency_code)->toBe('HKD');
    expect((string) $po->product_total)->not()->toBe('0.00');
});

it('derives vendor_product_total and fx from compact DSPIAE HKD rows and computes CAD unit_cost', function (): void {
    $csv = implode("\n", [
        'SKU,Qty,Unit Price (HKD),Amount (HKD)',
        'MP-20,5,13.21,66.05',
        'PT-AB,6,64.6,387.65',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae-compact.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'product_total' => 100,
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    $po = PurchaseOrder::query()->where('uuid', '=', $uuid)->firstOrFail();
    expect((string) $po->vendor_currency_code)->toBe('HKD');
    expect((string) $po->vendor_product_total)->toBe('453.70');
    expect((string) $po->fx_rate_to_cad)->toBe('0.220410');

    $mp20 = PurchaseOrderItem::query()
        ->where('purchase_order_id', '=', (int) $po->id)
        ->where('sku', '=', 'MP-20')
        ->firstOrFail();
    expect((string) ($mp20->vendor_unit_cost ?? ''))->toBe('13.2100');
    expect((string) ($mp20->unit_cost ?? ''))->toBe('2.9116');
});

it('imports compact DSPIAE HKD csv with lowercase hkd headers', function (): void {
    $csv = implode("\n", [
        'SKU,Qty,unit price (hkd),Amount (hkd)',
        'MP-20,5,13.21,66.03',
        'PT-AB,6,64.6,387.6',
    ]);

    $file = UploadedFile::fake()->createWithContent('dspiae-compact-lowercase.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Dspiae',
        'product_total' => 100,
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    $po = PurchaseOrder::query()->where('uuid', '=', $uuid)->firstOrFail();
    expect((string) $po->vendor_currency_code)->toBe('HKD');
    expect((string) $po->vendor_product_total)->toBe('453.63');

    $items = PurchaseOrderItem::query()
        ->where('purchase_order_id', '=', (int) $po->id)
        ->orderBy('id')
        ->get();
    expect($items)->toHaveCount(2);
    expect((string) ($items[0]->vendor_unit_cost ?? ''))->toBe('13.2100');
    expect((string) ($items[1]->vendor_unit_cost ?? ''))->toBe('64.6000');
});

