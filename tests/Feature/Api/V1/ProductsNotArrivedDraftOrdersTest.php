<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('counts not arrived from draft and ordered open POs when including draft orders', function (): void {
    $draftPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140001',
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
        'ordered_date' => null,
    ]);
    $orderedPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140002',
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
        'ordered_date' => '2026-05-20',
    ]);

    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140011',
        'sku' => 'NA-DRAFT-1',
        'description' => 'Pen knife set',
        'vendor' => 'Stedi',
        'main_type' => 'tools',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $draftPo->id,
        'product_id' => $product->id,
        'sku' => 'NA-DRAFT-1',
        'vendor' => 'Stedi',
        'qty_ordered' => 20,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $orderedPo->id,
        'product_id' => $product->id,
        'sku' => 'NA-DRAFT-1',
        'vendor' => 'Stedi',
        'qty_ordered' => 5,
    ]);

    $this->getJson('/api/v1/products?per_page=10&search=NA-DRAFT-1&not_arrived_include_draft_orders=1')
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'NA-DRAFT-1')
        ->assertJsonPath('data.0.not_arrived', 25);

    $this->getJson('/api/v1/products?per_page=10&search=NA-DRAFT-1&not_arrived_include_draft_orders=0')
        ->assertOk()
        ->assertJsonPath('data.0.not_arrived', 5);
});

it('counts received quantities until the purchase order is fully on shelves', function (): void {
    $product = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140021',
        'sku' => 'NA-RECEIVED-1',
        'description' => 'Received but not shelved',
        'vendor' => 'Stedi',
        'main_type' => 'tools',
    ]);

    $receivedPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140022',
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'CAD',
        'ordered_date' => '2026-05-20',
        'received_date' => '2026-06-10',
        'fully_on_shelves_date' => null,
    ]);
    $shelvedPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000140023',
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'CAD',
        'ordered_date' => '2026-04-20',
        'received_date' => '2026-05-10',
        'fully_on_shelves_date' => '2026-05-11',
    ]);

    foreach ([[$receivedPo, 7], [$shelvedPo, 11]] as [$purchaseOrder, $quantity]) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'sku' => 'NA-RECEIVED-1',
            'vendor' => 'Stedi',
            'qty_ordered' => $quantity,
            'qty_received' => $quantity,
        ]);
    }

    $this->getJson('/api/v1/products?per_page=10&search=NA-RECEIVED-1')
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'NA-RECEIVED-1')
        ->assertJsonPath('data.0.not_arrived', 7);
});
