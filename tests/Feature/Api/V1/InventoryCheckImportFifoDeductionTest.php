<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use App\Models\Product;

it('deducts FIFO lots when inventory check decreases available qty and goes negative when insufficient', function (): void {
    $product = Product::query()->create([
        'sku' => 'FIFO-UNDERFLOW-1',
        'barcode' => null,
        'description' => 'FIFO Underflow',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'price' => '10.00',
        'order_qty' => 0,
        'filled_qty' => 0,
        'available_qty' => 5,
        'extended' => '0.00',
    ]);

    InventoryLot::query()->create([
        'product_id' => $product->id,
        'purchase_order_item_id' => null,
        'source_type' => 'opening_balance',
        'unit_cost' => '10.0000',
        'shipping_per_unit' => '0.000000',
        'qty_received' => 3,
        'qty_remaining' => 3,
        'received_at' => now()->subDays(10),
    ]);

    $csv = implode("\n", [
        'Handle,Vendor,SKU,Type,Product Name,English name,Available amount,Selling price,Quantity in store,Difference,Notes',
        ',Plamod,FIFO-UNDERFLOW-1,HG,FIFO Underflow,,5,12.99,0,-5,check',
        '',
    ]);

    $tmp = tmpfile();
    fwrite($tmp, $csv);
    $meta = stream_get_meta_data($tmp);
    $path = (string) $meta['uri'];

    $res = $this->postJson('/api/v1/products/import-inventory-check', [
        'file' => new \Illuminate\Http\UploadedFile($path, 'inv.csv', 'text/csv', null, true),
    ])->assertOk();

    $checkUuid = (string) $res->json('inventory_check.uuid');

    $product->refresh();
    expect($product->available_qty)->toBe(0);

    $negative = InventoryLot::query()
        ->where('product_id', $product->id)
        ->where('source_type', 'negative_balance')
        ->first();

    expect($negative)->not->toBeNull()
        ->and((int) $negative->qty_remaining)->toBe(-2);

    $detail = $this->getJson("/api/v1/inventory-check/{$checkUuid}")->assertOk();
    expect((string) $detail->json('data.items.0.match_error'))->toContain('FIFO underflow');

    fclose($tmp);
});
