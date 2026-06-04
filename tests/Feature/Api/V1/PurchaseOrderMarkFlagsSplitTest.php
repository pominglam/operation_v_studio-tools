<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('marks published on shopify for all PO products', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000121001',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $kit = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000121011',
        'sku' => 'PUB-KIT',
        'description' => 'Kit',
        'main_type' => 'model kit',
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
    ]);
    $tool = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000121012',
        'sku' => 'PUB-TOOL',
        'description' => 'Nipper',
        'main_type' => 'tools',
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
    ]);

    foreach ([$kit, $tool] as $product) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/mark-published-on-shopify")
        ->assertOk()
        ->assertJsonPath('data.summary.updated', 2);

    expect($kit->refresh()->published_on_shopify)->toBeTrue();
    expect($tool->refresh()->published_on_shopify)->toBeTrue();
});

it('marks latest arrival for non-tools only', function (): void {
    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000121021',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
    ]);

    $kit = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000121031',
        'sku' => 'LA-KIT',
        'description' => 'Kit',
        'main_type' => 'model kit',
        'vendor' => 'Plamod',
        'latest_arrival' => false,
    ]);
    $tool = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000121032',
        'sku' => 'LA-TOOL',
        'description' => 'Nipper',
        'main_type' => 'tools',
        'vendor' => 'Plamod',
        'latest_arrival' => false,
    ]);

    foreach ([$kit, $tool] as $product) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $this->postJson("/api/v1/purchase-orders/{$po->uuid}/workflow-actions/mark-latest-arrival")
        ->assertOk()
        ->assertJsonPath('data.summary.updated', 1)
        ->assertJsonPath('data.summary.skipped_tools', 1);

    expect($kit->refresh()->latest_arrival)->toBeTrue();
    expect($tool->refresh()->latest_arrival)->toBeFalse();
});
