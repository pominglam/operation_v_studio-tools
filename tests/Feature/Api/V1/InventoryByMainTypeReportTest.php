<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Str;

it('returns inventory stats grouped by product type for on-hand stock', function (): void {
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'KIT-A',
        'description' => 'Kit A',
        'main_type' => 'model kit',
        'type' => 'HG',
        'available_qty' => 3,
        'latest_landed_unit_cost' => '10.00',
    ]);
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'KIT-B',
        'description' => 'Kit B',
        'main_type' => 'model kit',
        'type' => 'HG',
        'available_qty' => 2,
        'latest_landed_unit_cost' => '5.50',
    ]);
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'TOOL-A',
        'description' => 'Tool A',
        'main_type' => 'tools',
        'type' => 'pliers',
        'available_qty' => 4,
        'latest_landed_unit_cost' => '2.00',
    ]);
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'NO-STOCK',
        'description' => 'Catalog only',
        'main_type' => 'model kit',
        'type' => 'MG',
        'available_qty' => 0,
        'latest_landed_unit_cost' => '99.00',
    ]);
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'MISSING-COST',
        'description' => 'Missing landed cost',
        'main_type' => 'supplies',
        'type' => 'tape',
        'available_qty' => 1,
        'latest_landed_unit_cost' => null,
    ]);

    $res = $this->getJson('/api/v1/reports/inventory-by-main-type');

    $res->assertOk()
        ->assertJsonPath('data.data_source', 'products')
        ->assertJsonPath('data.scope', 'active_products_on_hand_available_qty')
        ->assertJsonPath('data.currency', 'CAD')
        ->assertJsonPath('data.totals.catalog_skus', 5)
        ->assertJsonPath('data.totals.skus_on_hand', 4)
        ->assertJsonPath('data.totals.quantity_on_hand', 10)
        ->assertJsonPath('data.totals.skus_missing_landed_cost', 1);

    $rows = collect($res->json('data.rows'))->keyBy('type');

    expect($rows['HG']['main_type'])->toBe('model kit')
        ->and($rows['HG']['catalog_skus'])->toBe(2)
        ->and($rows['HG']['skus_on_hand'])->toBe(2)
        ->and($rows['HG']['quantity_on_hand'])->toBe(5)
        ->and($rows['HG']['estimated_landed_value'])->toBe('41.00')
        ->and($rows['pliers']['quantity_on_hand'])->toBe(4)
        ->and($rows['pliers']['estimated_landed_value'])->toBe('8.00')
        ->and($rows['tape']['skus_missing_landed_cost'])->toBe(1)
        ->and($rows['tape']['estimated_landed_value'])->toBe('0.00');
});

it('sums lifetime received and sold units by product type', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'RECV-SOLD-1',
        'description' => 'Received and sold kit',
        'main_type' => 'model kit',
        'type' => 'HG',
        'available_qty' => 3,
    ]);

    $receivedPo = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-05-01',
        'ordered_date' => '2026-04-20',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $receivedPo->id,
        'product_id' => $product->id,
        'sku' => 'RECV-SOLD-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 10,
        'qty_received' => 10,
    ]);

    $res = $this->getJson('/api/v1/reports/inventory-by-main-type');

    $res->assertOk()
        ->assertJsonPath('data.totals.units_received', 10)
        ->assertJsonPath('data.totals.units_sold', 7);

    $row = collect($res->json('data.rows'))->firstWhere('type', 'HG');
    expect($row['units_received'] ?? null)->toBe(10)
        ->and($row['units_sold'] ?? null)->toBe(7);
});

it('groups blank product types under unset label', function (): void {
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'BLANK-TYPE',
        'description' => 'Blank type',
        'main_type' => 'model kit',
        'type' => '',
        'available_qty' => 2,
        'latest_landed_unit_cost' => '1.00',
    ]);

    $res = $this->getJson('/api/v1/reports/inventory-by-main-type');

    $res->assertOk();

    $row = collect($res->json('data.rows'))->firstWhere('type', '');
    expect($row)->not->toBeNull()
        ->and($row['type_label'])->toBe('(unset)')
        ->and($row['quantity_on_hand'])->toBe(2);
});

it('sums not arrived qty from open purchase orders by product type', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'NA-KIT-1',
        'description' => 'Inbound kit',
        'main_type' => 'model kit',
        'type' => 'HG',
        'available_qty' => 1,
        'latest_landed_unit_cost' => '10.00',
    ]);

    $openPo = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
        'ordered_date' => '2026-05-20',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $openPo->id,
        'product_id' => $product->id,
        'sku' => 'NA-KIT-1',
        'vendor' => 'Plamod',
        'qty_ordered' => 12,
    ]);

    $res = $this->getJson('/api/v1/reports/inventory-by-main-type');

    $res->assertOk()
        ->assertJsonPath('data.not_arrived_includes_draft_pos', true)
        ->assertJsonPath('data.totals.not_arrived', 12);

    $row = collect($res->json('data.rows'))->firstWhere('type', 'HG');
    expect($row['not_arrived_skus'] ?? null)->toBe(1)
        ->and($row['not_arrived'] ?? null)->toBe(12)
        ->and($row['estimated_not_landed_value'] ?? null)->toBe('120.00');
});

it('counts unique not arrived skus separately from not arrived unit qty', function (): void {
    $withInboundA = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'NA-SKU-A',
        'description' => 'Inbound A',
        'main_type' => 'model kit',
        'type' => 'HG',
    ]);
    $withInboundB = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'NA-SKU-B',
        'description' => 'Inbound B',
        'main_type' => 'model kit',
        'type' => 'MG',
    ]);
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'NA-SKU-C',
        'description' => 'No inbound',
        'main_type' => 'model kit',
        'type' => 'RG',
    ]);

    $openPo = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
        'ordered_date' => '2026-05-20',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $openPo->id,
        'product_id' => $withInboundA->id,
        'sku' => 'NA-SKU-A',
        'vendor' => 'Plamod',
        'qty_ordered' => 4,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $openPo->id,
        'product_id' => $withInboundB->id,
        'sku' => 'NA-SKU-B',
        'vendor' => 'Plamod',
        'qty_ordered' => 7,
    ]);

    $res = $this->getJson('/api/v1/reports/inventory-by-main-type');

    $res->assertOk()
        ->assertJsonPath('data.totals.not_arrived_skus', 2)
        ->assertJsonPath('data.totals.not_arrived', 11);
});

it('matches the products grid not arrived total for the default draft PO setting', function (): void {
    $kit = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'NA-PARITY-KIT',
        'description' => 'Parity kit',
        'main_type' => 'model kit',
        'type' => 'HG',
    ]);
    $tool = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'NA-PARITY-TOOL',
        'description' => 'Parity tool',
        'main_type' => 'tools',
        'type' => 'pliers',
    ]);

    $draftPo = PurchaseOrder::query()->create([
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
        'ordered_date' => null,
    ]);
    $orderedPo = PurchaseOrder::query()->create([
        'vendor' => 'Stedi',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
        'ordered_date' => '2026-05-20',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $draftPo->id,
        'product_id' => $kit->id,
        'sku' => 'NA-PARITY-KIT',
        'vendor' => 'Stedi',
        'qty_ordered' => 7,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $orderedPo->id,
        'product_id' => $tool->id,
        'sku' => 'NA-PARITY-TOOL',
        'vendor' => 'Stedi',
        'qty_ordered' => 4,
    ]);

    $productsRes = $this->getJson('/api/v1/products?per_page=1000&search=NA-PARITY');
    $productsTotal = collect($productsRes->json('data'))
        ->sum(static fn (array $row): int => (int) ($row['not_arrived'] ?? 0));

    $reportRes = $this->getJson('/api/v1/reports/inventory-by-main-type');

    expect($productsTotal)->toBe(11)
        ->and($reportRes->json('data.totals.not_arrived'))->toBe(11);
});

it('excludes archived products from all report counts', function (): void {
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'ACTIVE-KIT',
        'description' => 'Active kit',
        'main_type' => 'model kit',
        'type' => 'HG',
        'available_qty' => 2,
        'latest_landed_unit_cost' => '10.00',
    ]);
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'ARCHIVED-KIT',
        'description' => 'Archived kit',
        'main_type' => 'model kit',
        'type' => 'HG',
        'available_qty' => 99,
        'latest_landed_unit_cost' => '10.00',
        'archived_at' => now(),
    ]);

    $res = $this->getJson('/api/v1/reports/inventory-by-main-type');

    $res->assertOk()
        ->assertJsonPath('data.totals.catalog_skus', 1)
        ->assertJsonPath('data.totals.skus_on_hand', 1)
        ->assertJsonPath('data.totals.quantity_on_hand', 2);

    $row = collect($res->json('data.rows'))->firstWhere('type', 'HG');
    expect($row['catalog_skus'] ?? null)->toBe(1)
        ->and($row['quantity_on_hand'] ?? null)->toBe(2);
});
