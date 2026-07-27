<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

it('filters price research products by purchase order uuid', function (): void {
    $in = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020001',
        'sku' => 'PR-PO-IN',
        'description' => 'In PO',
    ]);
    $out = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020002',
        'sku' => 'PR-PO-OUT',
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
        'qty_ordered' => 1,
    ]);

    $res = $this->getJson("/api/v1/price-research/products?per_page=100&purchase_order_uuid={$po->uuid}");
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'PR-PO-IN')
        ->assertJsonMissing(['sku' => 'PR-PO-OUT']);
});

it('filters price research products by multiple purchase order uuids', function (): void {
    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020101',
        'sku' => 'PR-PO-M1',
        'description' => 'In PO 1',
    ]);
    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020102',
        'sku' => 'PR-PO-M2',
        'description' => 'In PO 2',
    ]);
    $out = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020103',
        'sku' => 'PR-PO-MOUT',
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

    $res = $this->getJson('/api/v1/price-research/products?per_page=100&purchase_order_uuids[]='.$po1->uuid.'&purchase_order_uuids[]='.$po2->uuid);
    $res->assertOk()
        ->assertJsonFragment(['sku' => 'PR-PO-M1'])
        ->assertJsonFragment(['sku' => 'PR-PO-M2'])
        ->assertJsonMissing(['sku' => 'PR-PO-MOUT']);
});

it('filters selected PO price research products by novelty = new', function (): void {
    $existing = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020201',
        'sku' => 'PR-PO-NOVELTY-NEW-EXISTING',
        'description' => 'Existing before selected PO',
    ]);
    $new = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020202',
        'sku' => 'PR-PO-NOVELTY-NEW-NEW',
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

    $res = $this->getJson('/api/v1/price-research/products?per_page=100&purchase_order_uuids[]='.$selectedPo->uuid.'&po_product_novelty=new');
    $res->assertOk()
        ->assertJsonFragment(['sku' => 'PR-PO-NOVELTY-NEW-NEW'])
        ->assertJsonMissing(['sku' => 'PR-PO-NOVELTY-NEW-EXISTING']);
});

it('filters selected PO price research products by novelty = existing', function (): void {
    $existing = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020301',
        'sku' => 'PR-PO-NOVELTY-EXISTING-EXISTING',
        'description' => 'Existing before selected PO',
    ]);
    $new = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000020302',
        'sku' => 'PR-PO-NOVELTY-EXISTING-NEW',
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

    $res = $this->getJson('/api/v1/price-research/products?per_page=100&purchase_order_uuids[]='.$selectedPo->uuid.'&po_product_novelty=existing');
    $res->assertOk()
        ->assertJsonFragment(['sku' => 'PR-PO-NOVELTY-EXISTING-EXISTING'])
        ->assertJsonMissing(['sku' => 'PR-PO-NOVELTY-EXISTING-NEW']);
});

it('rejects invalid purchase order uuid for price research products', function (): void {
    $this->getJson('/api/v1/price-research/products?purchase_order_uuid=not-a-uuid')->assertStatus(422);
});

it('rejects invalid purchase order uuids array for price research products', function (): void {
    $this->getJson('/api/v1/price-research/products?purchase_order_uuids[]=not-a-uuid')->assertStatus(422);
});

it('rejects invalid novelty filter for price research products', function (): void {
    $this->getJson('/api/v1/price-research/products?po_product_novelty=invalid')->assertStatus(422);
});
