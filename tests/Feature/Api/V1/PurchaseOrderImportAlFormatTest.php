<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;

it('imports AL csv and normalizes BAN sku format', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000452001',
        'sku' => '5058778',
        'barcode' => null,
        'description' => 'Existing Product',
        'type' => null,
        'vendor' => 'Bandai',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 0,
    ]);

    $csv = implode("\n", [
        'Title,Option1 Value,SKU,Quote,Qty*,Total',
        "HGUC RGZ-91 Re-GZ (High Grade Char's Counterattack 1/144),Default Title,BAN5058778.00,27.55,1,27.55",
        '',
    ]);
    $file = UploadedFile::fake()->createWithContent('al.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'AL',
        'file' => $file,
    ])->assertOk();

    $poUuid = (string) $res->json('purchase_order_uuid');
    $po = PurchaseOrder::query()->where('uuid', $poUuid)->firstOrFail();
    $item = PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->firstOrFail();

    expect($item->sku)->toBe('5058778');
    expect((string) $item->unit_cost)->toBe('27.5500');
    expect((int) $item->qty_ordered)->toBe(1);
});

it('creates missing products for AL csv rows that do not exist locally', function (): void {
    $csv = implode("\n", [
        'Title,Option1 Value,SKU,Quote,Qty*,Total',
        'Master Grade (MG) 1/100 YMS-15 Gyan,Default Title,BAN5063510.00,58.75,1,58.75',
        '',
    ]);
    $file = UploadedFile::fake()->createWithContent('al-missing.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'AL',
        'file' => $file,
    ])->assertOk();

    $res->assertJsonPath('items', 1);

    $created = Product::query()->where('sku', '5063510')->firstOrFail();
    expect((string) $created->vendor)->toBe('AL');
    expect((string) $created->description)->toBe('Master Grade (MG) 1/100 YMS-15 Gyan');
});
