<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('imports handles scoped to a purchase order (ignores other SKUs)', function (): void {
    Storage::fake('local');

    $pA = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000221111',
        'sku' => 'SKU-A',
        'barcode' => '111',
        'description' => 'A',
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 0,
        'handle' => null,
    ]);
    $pC = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000221333',
        'sku' => 'SKU-C',
        'barcode' => '333',
        'description' => 'C',
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 0,
        'handle' => null,
    ]);

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000222222',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'notes' => null,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $pA->id,
        'sku' => 'SKU-A',
        'vendor' => 'Plamod',
        'unit_cost' => null,
        'vendor_unit_cost' => null,
        'qty_ordered' => 1,
        'qty_shipped' => null,
        'qty_received' => null,
    ]);

    $csv = "Handle,Title,Variant SKU\nh-a,Title A,SKU-A\nh-c,Title C,SKU-C\n";
    $file = UploadedFile::fake()->createWithContent('handles.csv', $csv);

    $res = $this->postJson('/api/v1/products/import-handles', [
        'file' => $file,
        'purchase_order_uuid' => $po->uuid,
    ]);

    $res->assertOk();
    $res->assertJsonPath('updated', 1);
    $res->assertJsonPath('scoped_purchase_order_uuid', $po->uuid);

    $pA->refresh();
    $pC->refresh();

    expect($pA->handle)->toBe('h-a');
    expect($pC->handle)->toBeNull();
});

