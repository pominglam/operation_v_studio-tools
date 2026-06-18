<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('dry-run reports duplicate purchase order line groups without changing data', function (): void {
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140001',
        'sku' => 'DEDUPE-SKU',
        'description' => 'Dedupe test product',
        'vendor' => 'Plamod',
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140002',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'DEDUPE-SKU',
        'vendor' => 'Plamod',
        'unit_cost' => '10.0000',
        'qty_ordered' => 2,
        'qty_received' => 4,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'DEDUPE-SKU',
        'vendor' => 'Plamod',
        'unit_cost' => '10.0000',
        'qty_ordered' => 2,
        'qty_received' => 4,
    ]);

    $this->artisan('purchase-orders:dedupe-lines', ['--po' => $po->uuid])
        ->expectsOutputToContain('Would merge 1 duplicate group(s).')
        ->assertSuccessful();

    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->count())->toBe(2);
});

it('executes duplicate purchase order line merges', function (): void {
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140011',
        'sku' => 'DEDUPE-EXEC-SKU',
        'description' => 'Dedupe execute product',
        'vendor' => 'Plamod',
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140012',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $survivor = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'DEDUPE-EXEC-SKU',
        'vendor' => 'Plamod',
        'unit_cost' => '26.5100',
        'qty_ordered' => 2,
        'qty_received' => 4,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'DEDUPE-EXEC-SKU',
        'vendor' => 'Plamod',
        'unit_cost' => '25.7300',
        'qty_ordered' => 2,
        'qty_received' => 4,
    ]);

    $this->artisan('purchase-orders:dedupe-lines', [
        '--po' => $po->uuid,
        '--execute' => true,
    ])
        ->expectsConfirmation('Merge duplicate PO lines in the database?', 'yes')
        ->expectsOutputToContain('Merged 1 duplicate group(s).')
        ->assertSuccessful();

    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->count())->toBe(1);

    $survivor->refresh();
    expect($survivor->qty_ordered)->toBe(4);
    expect($survivor->qty_received)->toBe(4);
    expect((string) $survivor->unit_cost)->toBe('26.1200');
});
