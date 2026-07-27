<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;

it('persists supplier_order_id on new PO import', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000451201',
        'sku' => 'MS-104',
        'barcode' => 'E2E-201',
        'description' => 'Stedi Item',
        'type' => null,
        'vendor' => 'Stedi',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 0,
    ]);

    $csv = implode("\n", [
        'SKU,Qty,Unit Price (HK$),Amount (HK$)',
        'MS-104,1,30.73,30.73',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('stedi-simple.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Stedi',
        'supplier_order_id' => 'SUP-98765',
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect($po->supplier_order_id)->toBe('SUP-98765');
});

it('updates supplier_order_id when re-importing into an existing PO', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000451202',
        'sku' => 'MS-105',
        'barcode' => 'E2E-202',
        'description' => 'Stedi Item 2',
        'type' => null,
        'vendor' => 'Stedi',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 0,
    ]);

    $csv = implode("\n", [
        'SKU,Qty,Unit Price (HK$),Amount (HK$)',
        'MS-105,2,10.00,20.00',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('stedi-simple.csv', $csv);

    $create = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Stedi',
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($create->json('purchase_order_uuid') ?? '');

    $reimport = UploadedFile::fake()->createWithContent('stedi-simple-v2.csv', $csv);

    $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Stedi',
        'purchase_order_uuid' => $uuid,
        'supplier_order_id' => 'SUP-REIMPORT-1',
        'file' => $reimport,
    ])->assertOk();

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect($po->supplier_order_id)->toBe('SUP-REIMPORT-1');
});
