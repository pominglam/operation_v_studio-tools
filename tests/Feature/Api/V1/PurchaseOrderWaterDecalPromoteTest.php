<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductSellingPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('previews merge intention when WD sku already exists in catalog', function (): void {
    $canonical = Product::query()->create([
        'sku' => 'WD-MG-223',
        'description' => 'Water decal - MG BARBATOS LUPUS',
        'main_type' => 'water decals',
        'type' => 'MG',
        'vendor' => 'Dspiae',
        'handle' => 'water-decal-mg-barbatos-lupus',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $canonical->id,
        'product_uuid' => $canonical->uuid,
        'selling_price' => '7.99',
        'currency' => 'CAD',
    ]);

    $duplicate = Product::query()->create([
        'sku' => 'MG-223',
        'description' => 'MG Barbatos Lupus',
        'main_type' => 'model kit',
        'type' => 'MG',
        'vendor' => 'Water Decals',
    ]);

    $po = PurchaseOrder::query()->create(['vendor' => 'Other/multi']);
    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $duplicate->id,
        'sku' => 'MG-223',
        'vendor' => 'Other/multi',
        'unit_cost' => '2.1600',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/water-decals/preview", [
        'item_ids' => [$item->id],
    ])->assertOk()
        ->assertJsonPath('rows.0.intention', 'merge')
        ->assertJsonPath('rows.0.proposed_sku', 'WD-MG-223')
        ->assertJsonPath('rows.0.merge_target.sku', 'WD-MG-223')
        ->assertJsonPath('rows.0.merge_target.selling_price', '7.99');
});

it('previews promote intention for slug sku with WD prefix', function (): void {
    $product = Product::query()->create([
        'sku' => 'GHOST',
        'description' => 'MG NT-1 Alex 2.0',
        'main_type' => 'model kit',
        'type' => 'MG',
        'vendor' => 'Water Decals',
    ]);

    $po = PurchaseOrder::query()->create(['vendor' => 'Other/multi']);
    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'GHOST',
        'vendor' => 'Other/multi',
        'unit_cost' => '2.7000',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/water-decals/preview", [
        'item_ids' => [$item->id],
    ])->assertOk()
        ->assertJsonPath('rows.0.intention', 'promote')
        ->assertJsonPath('rows.0.proposed_sku', 'WD-GHOST')
        ->assertJsonPath('rows.0.proposed_vendor', 'Water Decals');
});

it('does not merge rg sazabi slug into WD-RG-55', function (): void {
    Product::query()->create([
        'sku' => 'WD-RG-55',
        'description' => 'Water decal - RG Sazabi ver. FF',
        'main_type' => 'water decals',
        'type' => 'RG',
        'vendor' => 'Water Decals',
    ]);

    $product = Product::query()->create([
        'sku' => 'rg-sazabi-r007',
        'description' => 'RG Sazabi (R007)',
        'main_type' => 'model kit',
        'type' => 'RG',
        'vendor' => 'Water Decals',
    ]);

    $po = PurchaseOrder::query()->create(['vendor' => 'Other/multi']);
    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'rg-sazabi-r007',
        'vendor' => 'Other/multi',
        'unit_cost' => '1.6200',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/water-decals/preview", [
        'item_ids' => [$item->id],
    ])->assertOk()
        ->assertJsonPath('rows.0.intention', 'promote')
        ->assertJsonPath('rows.0.proposed_sku', 'WD-rg-sazabi-r007')
        ->assertJsonPath('rows.0.merge_target', null);
});

it('applies merge after confirm and removes orphan catalog row', function (): void {
    $canonical = Product::query()->create([
        'sku' => 'WD-MG-223',
        'description' => 'Water decal - MG BARBATOS LUPUS',
        'main_type' => 'water decals',
        'type' => 'MG',
        'vendor' => 'Dspiae',
    ]);

    $duplicate = Product::query()->create([
        'sku' => 'MG-223',
        'description' => 'MG Barbatos Lupus',
        'main_type' => 'model kit',
        'type' => 'MG',
        'vendor' => 'Water Decals',
    ]);

    $po = PurchaseOrder::query()->create(['vendor' => 'Other/multi']);
    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $duplicate->id,
        'sku' => 'MG-223',
        'vendor' => 'Other/multi',
        'unit_cost' => '2.1600',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/water-decals/apply", [
        'rows' => [[
            'item_id' => $item->id,
            'sku' => 'WD-MG-223',
            'description' => 'Water decal - MG Barbatos Lupus',
            'vendor' => 'Water Decals',
            'type' => 'MG',
            'confirm_merge' => true,
        ]],
    ])->assertOk()
        ->assertJsonPath('water_decals.merged', 1);

    $item->refresh();
    expect($item->product_id)->toBe($canonical->id)
        ->and($item->sku)->toBe('WD-MG-223');

    expect(Product::query()->where('sku', 'MG-223')->exists())->toBeFalse();

    $canonical->refresh();
    expect($canonical->vendor)->toBe('Water Decals');
});

it('requires confirm merge before merging', function (): void {
    Product::query()->create([
        'sku' => 'WD-MG-223',
        'description' => 'Water decal - MG BARBATOS LUPUS',
        'main_type' => 'water decals',
        'type' => 'MG',
        'vendor' => 'Water Decals',
    ]);

    $duplicate = Product::query()->create([
        'sku' => 'MG-223',
        'description' => 'MG Barbatos Lupus',
        'main_type' => 'model kit',
        'type' => 'MG',
    ]);

    $po = PurchaseOrder::query()->create(['vendor' => 'Other/multi']);
    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $duplicate->id,
        'sku' => 'MG-223',
        'vendor' => 'Other/multi',
        'unit_cost' => '2.1600',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/water-decals/apply", [
        'rows' => [[
            'item_id' => $item->id,
            'sku' => 'WD-MG-223',
            'description' => 'Water decal - MG Barbatos Lupus',
            'vendor' => 'Water Decals',
            'type' => 'MG',
            'confirm_merge' => false,
        ]],
    ])->assertStatus(422)
        ->assertJsonPath('water_decals.errors.0', 'Confirm merge for PO line '.$item->id.'.');
});

it('promotes slug sku in place with WD prefix', function (): void {
    $product = Product::query()->create([
        'sku' => 'GHOST',
        'description' => 'MG NT-1 Alex 2.0',
        'main_type' => 'model kit',
        'type' => 'MG',
        'vendor' => 'Water Decals',
    ]);

    $po = PurchaseOrder::query()->create(['vendor' => 'Other/multi']);
    $item = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => 'GHOST',
        'vendor' => 'Other/multi',
        'unit_cost' => '2.7000',
        'qty_ordered' => 1,
    ]);

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/water-decals/apply", [
        'rows' => [[
            'item_id' => $item->id,
            'sku' => 'WD-GHOST',
            'description' => 'Water decal - MG NT-1 Alex 2.0',
            'vendor' => 'Water Decals',
            'type' => 'MG',
        ]],
    ])->assertOk()
        ->assertJsonPath('water_decals.promoted', 1);

    expect(Product::query()->where('sku', 'WD-GHOST')->exists())->toBeTrue()
        ->and(Product::query()->where('sku', 'GHOST')->exists())->toBeFalse();

    $item->refresh();
    expect($item->sku)->toBe('WD-GHOST');
});
