<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrders\OpeningBalancePurchaseOrderReassignmentService;

it('moves an opening balance PO item to the correct vendor opening balance PO', function (): void {
    $product = Product::query()->create([
        'sku' => 'MP-26',
        'barcode' => null,
        'description' => 'Test',
        'type' => 'ACC',
        'vendor' => 'Plamod',
        'price' => '2.40',
        'available_qty' => 0,
    ]);

    $poUnknown = PurchaseOrder::query()->create([
        'vendor' => 'Unknown',
        'shipping_total' => '0.00',
        'received_date' => now()->toDateString(),
        'notes' => 'Opening balance backfill (Unknown).',
    ]);

    $poPlamod = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'shipping_total' => '0.00',
        'received_date' => now()->toDateString(),
        'notes' => 'Opening balance backfill (Plamod).',
    ]);

    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poUnknown->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Unknown',
        'unit_cost' => '2.4000',
        'qty_received' => 0,
        'qty_ordered' => null,
        'qty_shipped' => null,
    ]);

    $svc = app(OpeningBalancePurchaseOrderReassignmentService::class);
    $res = $svc->reassignForSku('MP-26');

    expect($res['moved_items'])->toBe(1)
        ->and($res['to_po_uuid'])->toBe($poPlamod->uuid);

    $item->refresh();
    expect($item->purchase_order_id)->toBe($poPlamod->id)
        ->and($item->qty_ordered)->toBe(0)
        ->and($item->qty_shipped)->toBe(0);
});


