<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;

it('does not error when received_date is provided and lots are created (regression)', function (): void {
    Product::query()->create([
        'sku' => 'PO-RECVD-1',
        'barcode' => null,
        'description' => 'Received date regression product',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'price' => '10.00',
        'available_qty' => 0,
    ]);

    $csv = implode("\n", [
        'SKU,Unit cost,Qty ordered,Qty shipped,Qty received',
        'PO-RECVD-1,12.34,1,1,1',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('po.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Plamod',
        'ordered_date' => '2026-01-05',
        'shipped_date' => '2026-01-13',
        'estimated_arrival_date' => '2026-01-20',
        'received_date' => '2026-01-21',
        'shipping_total' => 1.00,
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    $lot = InventoryLot::query()
        ->whereHas('purchaseOrderItem', fn ($q) => $q->where('purchase_order_id', $po->id))
        ->firstOrFail();

    expect($po->estimated_arrival_date?->toDateString())->toBe('2026-01-20');
    expect($lot->received_at->toDateString())->toBe('2026-01-21');
});

