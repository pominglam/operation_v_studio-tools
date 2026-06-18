<?php

declare(strict_types=1);

use App\Services\PurchaseOrders\PurchaseOrderLineMergeService;

it('merges duplicate import rows by sku with qty-weighted unit cost', function (): void {
    $service = new PurchaseOrderLineMergeService;

    $result = $service->mergeParsedImportRows([
        [
            'row' => 2,
            'sku' => '5058777',
            'unit_cost' => '26.51',
            'qty_ordered' => 2,
            'qty_shipped' => 2,
            'qty_received' => 2,
            'product_name' => 'Product A',
            'barcode' => '111',
        ],
        [
            'row' => 20,
            'sku' => '5058777',
            'unit_cost' => '25.73',
            'qty_ordered' => 2,
            'qty_shipped' => 2,
            'qty_received' => 2,
            'product_name' => null,
            'barcode' => null,
        ],
    ]);

    expect($result['merged_count'])->toBe(1);
    expect($result['rows'])->toHaveCount(1);

    $row = $result['rows'][0];
    expect($row['sku'])->toBe('5058777');
    expect($row['qty_ordered'])->toBe(4);
    expect($row['qty_received'])->toBe(4);
    expect($row['product_name'])->toBe('Product A');
    expect($row['barcode'])->toBe('111');
    expect($row['unit_cost'])->toBe('26.1200');
});

it('uses a single qty_received when duplicate dedup lines share the same value', function (): void {
    $service = new PurchaseOrderLineMergeService;

    expect($service->mergeQtyReceivedForDedup(4, 4))->toBe(4);
});

it('sums qty_received when duplicate dedup lines have different values', function (): void {
    $service = new PurchaseOrderLineMergeService;

    expect($service->mergeQtyReceivedForDedup(2, 3))->toBe(5);
});
