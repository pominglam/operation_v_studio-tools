<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;

it('imports a Stedi simple CSV (SKU, Qty, Unit Price (HK$), Amount (HK$))', function (): void {
    // Existing product so we link line items correctly.
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000451111',
        'sku' => 'MS-104',
        'barcode' => 'E2E-111',
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
        'MS-104,60,30.73,1843.81',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('stedi-simple.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Stedi',
        'file' => $file,
    ]);

    $res->assertOk();
    $res->assertJsonPath('items', 1);
});

it('imports Stedi simple CSV with lowercase HKD headers', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000451112',
        'sku' => 'MS-12',
        'barcode' => 'E2E-112',
        'description' => 'Stedi Item Lowercase Header',
        'type' => null,
        'vendor' => 'Stedi',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 0,
    ]);

    $csv = implode("\n", [
        'SKU,Qty,unit price (hkd),Amount (hkd)',
        'MS-12,3,17.94,53.82',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('stedi-simple-lowercase.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Stedi',
        'file' => $file,
    ]);

    $res->assertOk();
    $res->assertJsonPath('items', 1);

    $uuid = (string) $res->json('purchase_order_uuid');
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();

    expect((string) $po->vendor_currency_code)->toBe('HKD');
    expect((string) $po->vendor_product_total)->toBe('53.82');
});
