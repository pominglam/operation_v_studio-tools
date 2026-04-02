<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('filters products by purchase order uuid', function (): void {
    $in = Product::query()->create([
        'sku' => 'PO-FILTER-IN',
        'description' => 'In PO',
    ]);
    $out = Product::query()->create([
        'sku' => 'PO-FILTER-OUT',
        'description' => 'Not in PO',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'ordered_date' => '2026-01-01',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $in->id,
        'sku' => $in->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 2,
    ]);

    $res = $this->getJson("/api/v1/products?per_page=100&purchase_order_uuid={$po->uuid}");
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'PO-FILTER-IN')
        ->assertJsonMissing(['sku' => 'PO-FILTER-OUT']);
});

it('filters products by multiple purchase order uuids', function (): void {
    $p1 = Product::query()->create([
        'sku' => 'PO-MULTI-1',
        'description' => 'In PO 1',
    ]);
    $p2 = Product::query()->create([
        'sku' => 'PO-MULTI-2',
        'description' => 'In PO 2',
    ]);
    $out = Product::query()->create([
        'sku' => 'PO-MULTI-OUT',
        'description' => 'Not in any selected PO',
    ]);

    $po1 = PurchaseOrder::query()->create(['vendor' => 'Plamod', 'ordered_date' => '2026-01-01']);
    $po2 = PurchaseOrder::query()->create(['vendor' => 'Plamod', 'ordered_date' => '2026-01-02']);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po1->id,
        'product_id' => $p1->id,
        'sku' => $p1->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 1,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po2->id,
        'product_id' => $p2->id,
        'sku' => $p2->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 1,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&purchase_order_uuids[]='.$po1->uuid.'&purchase_order_uuids[]='.$po2->uuid);
    $res->assertOk()
        ->assertJsonFragment(['sku' => 'PO-MULTI-1'])
        ->assertJsonFragment(['sku' => 'PO-MULTI-2'])
        ->assertJsonMissing(['sku' => 'PO-MULTI-OUT']);
});

it('rejects invalid purchase order uuid for products index', function (): void {
    $this->getJson('/api/v1/products?purchase_order_uuid=not-a-uuid')->assertStatus(422);
});

it('rejects invalid purchase order uuids array for products index', function (): void {
    $this->getJson('/api/v1/products?purchase_order_uuids[]=not-a-uuid')->assertStatus(422);
});

it('filters selected PO products by novelty = new', function (): void {
    $existing = Product::query()->create([
        'sku' => 'PO-NOVELTY-NEW-EXISTING',
        'description' => 'Existing before selected PO',
    ]);
    $new = Product::query()->create([
        'sku' => 'PO-NOVELTY-NEW-NEW',
        'description' => 'New in selected PO',
    ]);

    $oldPo = PurchaseOrder::query()->create(['vendor' => 'Plamod', 'ordered_date' => '2026-01-01']);
    $selectedPo = PurchaseOrder::query()->create(['vendor' => 'Plamod', 'ordered_date' => '2026-01-02']);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $oldPo->id,
        'product_id' => $existing->id,
        'sku' => $existing->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 1,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $selectedPo->id,
        'product_id' => $existing->id,
        'sku' => $existing->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 1,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $selectedPo->id,
        'product_id' => $new->id,
        'sku' => $new->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 1,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&purchase_order_uuids[]='.$selectedPo->uuid.'&po_product_novelty=new');
    $res->assertOk()
        ->assertJsonFragment(['sku' => 'PO-NOVELTY-NEW-NEW'])
        ->assertJsonMissing(['sku' => 'PO-NOVELTY-NEW-EXISTING']);
});

it('filters selected PO products by novelty = existing', function (): void {
    $existing = Product::query()->create([
        'sku' => 'PO-NOVELTY-EXISTING-EXISTING',
        'description' => 'Existing before selected PO',
    ]);
    $new = Product::query()->create([
        'sku' => 'PO-NOVELTY-EXISTING-NEW',
        'description' => 'New in selected PO',
    ]);

    $oldPo = PurchaseOrder::query()->create(['vendor' => 'Plamod', 'ordered_date' => '2026-01-01']);
    $selectedPo = PurchaseOrder::query()->create(['vendor' => 'Plamod', 'ordered_date' => '2026-01-02']);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $oldPo->id,
        'product_id' => $existing->id,
        'sku' => $existing->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 1,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $selectedPo->id,
        'product_id' => $existing->id,
        'sku' => $existing->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 1,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $selectedPo->id,
        'product_id' => $new->id,
        'sku' => $new->sku,
        'vendor' => 'Plamod',
        'unit_cost' => '10.00',
        'qty_ordered' => 1,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&purchase_order_uuids[]='.$selectedPo->uuid.'&po_product_novelty=existing');
    $res->assertOk()
        ->assertJsonFragment(['sku' => 'PO-NOVELTY-EXISTING-EXISTING'])
        ->assertJsonMissing(['sku' => 'PO-NOVELTY-EXISTING-NEW']);
});

it('rejects invalid novelty filter for products index', function (): void {
    $this->getJson('/api/v1/products?po_product_novelty=invalid')->assertStatus(422);
});

