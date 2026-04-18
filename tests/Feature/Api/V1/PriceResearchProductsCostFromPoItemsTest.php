<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PriceResearch\PriceResearchQueryService;

it('shows unit cost + ship/unit + landed on price research page from latest PO when no lots exist', function (): void {
    $product = Product::query()->create([
        'sku' => 'PR-PO-COST-1',
        'barcode' => '6977151546258',
        'description' => 'PO cost fallback',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
        'shipping_total' => '15.00',
        'surcharge_total' => '5.00',
        'ordered_date' => '2025-12-31',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '10.00',
        'qty_ordered' => 2,
    ]);

    $po->refresh();
    expect((string) $po->shipping_total)->toBe('15.00');
    expect((string) $po->surcharge_total)->toBe('5.00');
    expect(PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->sum('qty_ordered'))->toBe(2);
    expect(PurchaseOrderItem::query()->where('product_id', $product->id)->count())->toBe(1);

    /** @var PriceResearchQueryService $query */
    $query = app(PriceResearchQueryService::class);
    $page = $query->paginateProductsWithQuotes(perPage: 25, search: 'PR-PO-COST-1');
    $row = $page->items()[0] ?? null;
    expect($row)->not->toBeNull();
    // Raw computed values (before resource formatting).
    expect((float) ($row?->latest_unit_cost ?? 0))->toBe(10.0);
    expect((float) ($row?->latest_shipping_per_unit ?? 0))->toBe(7.5);
    expect((float) ($row?->latest_surcharge_per_unit ?? 0))->toBe(2.5);

    // Shipping per unit = 15/2 = 7.50; landed includes surcharge allocation: 10 + 7.50 + (5/2=2.50) = 20.00
    $res = $this->getJson('/api/v1/price-research/products?per_page=25&search=PR-PO-COST-1');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'PR-PO-COST-1')
        ->assertJsonPath('data.0.cost', '10.00')
        ->assertJsonPath('data.0.shipping_per_unit', '7.50')
        ->assertJsonPath('data.0.landed_cost', '20.00');
});

it('falls back to ordered allocation when latest PO is received but qty_received sum is zero', function (): void {
    $product = Product::query()->create([
        'sku' => 'PR-PO-COST-RECEIVED-ZERO',
        'barcode' => '6977000000001',
        'description' => 'PO received zero qty fallback',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
        'shipping_total' => '24.64',
        'surcharge_total' => '0.00',
        'ordered_date' => '2026-04-14',
        'received_date' => '2026-04-18',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '70.00',
        'qty_ordered' => 8,
        'qty_received' => null,
    ]);

    /** @var PriceResearchQueryService $query */
    $query = app(PriceResearchQueryService::class);
    $page = $query->paginateProductsWithQuotes(perPage: 25, search: 'PR-PO-COST-RECEIVED-ZERO');
    $row = $page->items()[0] ?? null;
    expect($row)->not->toBeNull();
    expect((float) ($row?->latest_shipping_per_unit ?? 0))->toBe(3.08);

    $res = $this->getJson('/api/v1/price-research/products?per_page=25&search=PR-PO-COST-RECEIVED-ZERO');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'PR-PO-COST-RECEIVED-ZERO')
        ->assertJsonPath('data.0.shipping_per_unit', '3.08')
        ->assertJsonPath('data.0.landed_cost', '73.08');
});

it('uses received allocation when latest PO has qty_received but received_date is null', function (): void {
    $product = Product::query()->create([
        'sku' => 'PR-PO-COST-RECV-WITHOUT-DATE',
        'barcode' => '6977000000002',
        'description' => 'PO received qty without received date',
        'vendor' => 'Dspiae',
    ]);

    $other = Product::query()->create([
        'sku' => 'PR-PO-COST-RECV-WITHOUT-DATE-OTHER',
        'barcode' => '6977000000003',
        'description' => 'PO received qty without received date other',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
        'shipping_total' => '30.00',
        'surcharge_total' => '0.00',
        'ordered_date' => '2026-04-14',
        'received_date' => null,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '70.00',
        'qty_ordered' => 5,
        'qty_received' => 2,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $other->id,
        'sku' => $other->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '10.00',
        'qty_ordered' => 5,
        'qty_received' => 1,
    ]);

    /** @var PriceResearchQueryService $query */
    $query = app(PriceResearchQueryService::class);
    $page = $query->paginateProductsWithQuotes(perPage: 25, search: 'PR-PO-COST-RECV-WITHOUT-DATE');
    $row = $page->items()[0] ?? null;
    expect($row)->not->toBeNull();
    expect((float) ($row?->latest_shipping_per_unit ?? 0))->toBe(10.0);

    $res = $this->getJson('/api/v1/price-research/products?per_page=25&search=PR-PO-COST-RECV-WITHOUT-DATE');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'PR-PO-COST-RECV-WITHOUT-DATE')
        ->assertJsonPath('data.0.shipping_per_unit', '10.00')
        ->assertJsonPath('data.0.landed_cost', '80.00');
});

it('treats entered qty_received=0 as received allocation (not ordered fallback)', function (): void {
    $product = Product::query()->create([
        'sku' => 'PR-PO-COST-RECV-ZERO',
        'barcode' => '6977000000004',
        'description' => 'PO received zero allocation',
        'vendor' => 'Dspiae',
    ]);

    $other = Product::query()->create([
        'sku' => 'PR-PO-COST-RECV-ZERO-OTHER',
        'barcode' => '6977000000005',
        'description' => 'PO received zero allocation other',
        'vendor' => 'Dspiae',
    ]);

    $po = PurchaseOrder::query()->create([
        'vendor' => 'Dspiae',
        'shipping_total' => '30.00',
        'surcharge_total' => '0.00',
        'ordered_date' => '2026-04-14',
        'received_date' => null,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '70.00',
        'qty_ordered' => 5,
        'qty_received' => 0,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $other->id,
        'sku' => $other->sku,
        'vendor' => 'Dspiae',
        'unit_cost' => '10.00',
        'qty_ordered' => 5,
        'qty_received' => 0,
    ]);

    /** @var PriceResearchQueryService $query */
    $query = app(PriceResearchQueryService::class);
    $page = $query->paginateProductsWithQuotes(perPage: 25, search: 'PR-PO-COST-RECV-ZERO');
    $row = $page->items()[0] ?? null;
    expect($row)->not->toBeNull();
    expect($row?->latest_shipping_per_unit)->toBeNull();

    $res = $this->getJson('/api/v1/price-research/products?per_page=25&search=PR-PO-COST-RECV-ZERO');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'PR-PO-COST-RECV-ZERO')
        ->assertJsonPath('data.0.shipping_per_unit', null)
        ->assertJsonPath('data.0.landed_cost', '70.00');
});
