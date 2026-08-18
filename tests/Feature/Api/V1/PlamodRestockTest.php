<?php

declare(strict_types=1);

use App\Models\PlamodInstockItem;
use App\Models\PlamodInstockSyncLog;
use App\Models\PlamodPreorder;
use App\Models\PlamodPreorderOffer;
use App\Models\PlamodRestockCartRun;
use App\Models\PlamodRestockSkuDecision;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Products\Http\PlamodScraper;
use App\Support\Plamod\PlamodRestockCostCalculator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('calculates new landed cost breakdown with shipping percent', function (): void {
    $breakdown = PlamodRestockCostCalculator::newLandedBreakdown('10.00', 5.0);

    expect($breakdown)->toMatchArray([
        'product' => '10.00',
        'shipping' => '0.50',
        'landed' => '10.50',
    ]);
});

it('flags product cost delta above three percent', function (): void {
    expect(PlamodRestockCostCalculator::isProductCostDeltaAboveThreshold('10.00', '10.40'))->toBeTrue();
    expect(PlamodRestockCostCalculator::isProductCostDeltaAboveThreshold('10.00', '10.20'))->toBeFalse();
    expect(PlamodRestockCostCalculator::productCostDeltaPercent('10.00', '11.50'))->toBe(15.0);
});

it('returns restock proposal intersecting plamod in-stock with erp catalog', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'RESTOCK-1',
        'product_name' => 'Restock One',
        'price_stock' => '12.00',
        'release_date' => '2025-04-01',
        'release_date_label' => 'Apr 2025',
        'last_seen_at' => now(),
    ]);
    PlamodInstockItem::query()->create([
        'sku' => 'RESTOCK-ZERO',
        'product_name' => 'Zero reorder',
        'price_stock' => '8.00',
        'last_seen_at' => now(),
    ]);
    PlamodInstockItem::query()->create([
        'sku' => 'RESTOCK-NEW',
        'product_name' => 'Brand New Kit',
        'price_stock' => '20.00',
        'release_date' => '2026-01-01',
        'release_date_label' => 'Jan 2026',
        'last_seen_at' => now(),
    ]);
    PlamodInstockItem::query()->create([
        'sku' => 'RESTOCK-OTHER-VENDOR',
        'product_name' => 'Existing Other Vendor Kit',
        'price_stock' => '15.00',
        'last_seen_at' => now(),
    ]);

    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'RESTOCK-1',
        'description' => 'Restock One',
        'vendor' => 'Plamod',
        'type' => 'ACTION BASE',
        'available_qty' => 1,
        'maintain_qty' => 5,
        'latest_unit_cost' => '10.00',
        'latest_landed_unit_cost' => '10.50',
    ]);
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'RESTOCK-ZERO',
        'description' => 'Zero reorder',
        'vendor' => 'Plamod',
        'available_qty' => 10,
        'maintain_qty' => 10,
    ]);
    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'RESTOCK-OTHER-VENDOR',
        'description' => 'Existing Other Vendor Kit',
        'vendor' => 'AL',
        'available_qty' => 0,
        'maintain_qty' => 2,
    ]);
    $res = $this->getJson('/api/v1/plamod/restock/proposal');

    $res->assertOk();
    $existing = collect($res->json('data.existing'));
    expect($existing->pluck('sku')->all())
        ->toEqual(['RESTOCK-1', 'RESTOCK-OTHER-VENDOR', 'RESTOCK-ZERO']);
    expect($existing->firstWhere('sku', 'RESTOCK-1')['reorder_qty'])->toBe(4);
    expect($existing->firstWhere('sku', 'RESTOCK-1')['type'])->toBe('ACTION BASE');
    expect($existing->firstWhere('sku', 'RESTOCK-ZERO')['reorder_qty'])->toBe(0);
    expect($existing->firstWhere('sku', 'RESTOCK-ZERO')['proposed_qty'])->toBe(0);
    expect($existing->firstWhere('sku', 'RESTOCK-OTHER-VENDOR')['reorder_qty'])->toBe(2);
    expect(collect($res->json('data.new_products'))->pluck('sku')->all())->toContain('RESTOCK-NEW');
    expect(collect($res->json('data.new_products'))->pluck('sku')->all())
        ->not->toContain('RESTOCK-OTHER-VENDOR');
});

it('persists exclusions that automatically dismiss future matching new products', function (): void {
    foreach ([
        ['sku' => 'EXCLUDE-SERIES', 'product_name' => 'Far Future Kit', 'series' => 'Future Saga'],
        ['sku' => 'EXCLUDE-LINE', 'product_name' => 'Action Base 99 Clear', 'series' => null],
        ['sku' => 'KEEP-NEW', 'product_name' => 'Wanted Gundam', 'series' => 'Mobile Suit Gundam'],
    ] as $item) {
        PlamodInstockItem::query()->create([
            ...$item,
            'price_stock' => '10.00',
            'last_seen_at' => now(),
        ]);
    }

    $this->putJson('/api/v1/plamod/restock/settings', [
        'shipping_percent' => 5,
        'excluded_series' => ['Future Saga'],
        'excluded_product_terms' => ['action base'],
    ])->assertOk()
        ->assertJsonPath('data.excluded_series.0', 'Future Saga')
        ->assertJsonPath('data.excluded_product_terms.0', 'action base');

    $visible = $this->getJson('/api/v1/plamod/restock/proposal')->assertOk();
    expect(collect($visible->json('data.new_products'))->pluck('sku')->all())
        ->toContain('KEEP-NEW')
        ->not->toContain('EXCLUDE-SERIES', 'EXCLUDE-LINE');

    $withDismissed = $this->getJson('/api/v1/plamod/restock/proposal?hide_dismissed=0')->assertOk();
    $rows = collect($withDismissed->json('data.new_products'))->keyBy('sku');
    expect($rows->get('EXCLUDE-SERIES')['status'])->toBe('dismissed');
    expect($rows->get('EXCLUDE-LINE')['status'])->toBe('dismissed');

    PlamodRestockSkuDecision::query()->create([
        'sku' => 'EXCLUDE-SERIES',
        'status' => 'later',
    ]);
    $overridden = collect($this->getJson('/api/v1/plamod/restock/proposal')->json('data.new_products'))
        ->firstWhere('sku', 'EXCLUDE-SERIES');
    expect($overridden['status'])->toBe('later');
});

it('rejects empty automatic exclusion rules', function (): void {
    $this->putJson('/api/v1/plamod/restock/settings', [
        'shipping_percent' => 5,
        'excluded_series' => [''],
        'excluded_product_terms' => ['a'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['excluded_series.0', 'excluded_product_terms.0']);
});

it('returns plamod preorder committed qty and shipment breakdown on existing rows', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'PREORDER-SKU',
        'product_name' => 'Preorder Kit',
        'price_stock' => '12.00',
        'last_seen_at' => now(),
    ]);

    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'PREORDER-SKU',
        'description' => 'Preorder Kit',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 2,
    ]);

    PlamodPreorderOffer::query()->create([
        'sku' => 'PREORDER-SKU',
        'offer_key' => '5496',
        'offer_id' => '5496',
        'quantity' => 2,
        'eta_date' => '2025-10-15',
        'last_seen_at' => now(),
    ]);
    PlamodPreorderOffer::query()->create([
        'sku' => 'PREORDER-SKU',
        'offer_key' => '5972',
        'offer_id' => '5972',
        'quantity' => 2,
        'eta_date' => '2026-02-28',
        'last_seen_at' => now(),
    ]);

    $row = collect($this->getJson('/api/v1/plamod/restock/proposal')->json('data.existing'))
        ->firstWhere('sku', 'PREORDER-SKU');

    expect($row['preorder_committed_qty'])->toBe(4);
    expect($row['preorder_shipments'])->toHaveCount(2);
    expect($row['preorder_shipments'][0]['offer_id'])->toBe('5496');
    expect($row['preorder_shipments'][0]['quantity'])->toBe(2);
});

it('falls back to plamod preorders snapshot when offer rows are missing', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'PREORDER-FALLBACK',
        'product_name' => 'Fallback Kit',
        'price_stock' => '9.00',
        'last_seen_at' => now(),
    ]);

    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'PREORDER-FALLBACK',
        'description' => 'Fallback Kit',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 1,
    ]);

    PlamodPreorder::query()->create([
        'sku' => 'PREORDER-FALLBACK',
        'product_name' => 'Fallback Kit',
        'quantity_preorder' => 3,
        'eta_date' => '2025-11-01',
        'last_seen_at' => now(),
    ]);

    $row = collect($this->getJson('/api/v1/plamod/restock/proposal')->json('data.existing'))
        ->firstWhere('sku', 'PREORDER-FALLBACK');

    expect($row['preorder_committed_qty'])->toBe(3);
    expect($row['preorder_shipments'])->toHaveCount(1);
    expect($row['preorder_shipments'][0]['quantity'])->toBe(3);
});

it('returns extended new product fields and missing price meta', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'NEW-META',
        'product_name' => 'Meta Kit',
        'price_stock' => null,
        'series' => '30MF',
        'category' => 'Plastic Model Kits',
        'barcode' => '4901234567890',
        'source_image_url' => 'https://cdn.example/kit.jpg',
        'plamod_pdp_url' => 'https://plamod.com/retailer/products/NEW-META',
        'release_date' => '2026-06-01',
        'release_date_label' => 'Jun 2026',
        'last_seen_at' => now(),
    ]);
    PlamodInstockItem::query()->create([
        'sku' => 'NEW-PRICED',
        'product_name' => 'Priced Kit',
        'price_stock' => '18.00',
        'last_seen_at' => now(),
    ]);

    $res = $this->getJson('/api/v1/plamod/restock/proposal')->assertOk();

    $res->assertJsonPath('data.meta.new_missing_price_count', 1);
    $metaRow = collect($res->json('data.new_products'))->firstWhere('sku', 'NEW-META');
    expect($metaRow)->not->toBeNull();
    expect($metaRow['series'])->toBe('30MF');
    expect($metaRow['category'])->toBe('Plastic Model Kits');
    expect($metaRow['barcode'])->toBe('4901234567890');
    expect($metaRow['image_url'])->toBe('https://cdn.example/kit.jpg');
    expect($metaRow['price_missing'])->toBeTrue();
    expect($metaRow['plamod_pdp_url'])->toBe('https://plamod.com/retailer/products/NEW-META');
});

it('persists shipping percent setting', function (): void {
    $this->putJson('/api/v1/plamod/restock/settings', ['shipping_percent' => 7.5])->assertOk();

    $this->getJson('/api/v1/plamod/restock/settings')
        ->assertOk()
        ->assertJsonPath('data.shipping_percent', 7.5);
});

it('stores include and dismiss decisions for new skus', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'NEW-999',
        'product_name' => 'New SKU',
        'price_stock' => '15.00',
        'last_seen_at' => now(),
    ]);

    $this->putJson('/api/v1/plamod/restock/decisions/NEW-999', [
        'status' => 'included',
        'order_qty' => 3,
        'planned_maintain_qty' => 6,
    ])->assertOk();

    $this->assertDatabaseHas('plamod_restock_sku_decisions', [
        'sku' => 'NEW-999',
        'status' => 'included',
        'order_qty' => 3,
    ]);

    $this->assertDatabaseHas('plamod_restock_planned_maintain', [
        'sku' => 'NEW-999',
        'maintain_qty' => 6,
    ]);

    $this->putJson('/api/v1/plamod/restock/decisions/NEW-999', [
        'status' => 'dismissed',
    ])->assertOk();

    $this->assertDatabaseHas('plamod_restock_sku_decisions', [
        'sku' => 'NEW-999',
        'status' => 'dismissed',
    ]);
});

it('allows an included new sku to be updated to zero order qty', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'NEW-ZERO',
        'product_name' => 'Zero Qty SKU',
        'price_stock' => '15.00',
        'last_seen_at' => now(),
    ]);

    $this->putJson('/api/v1/plamod/restock/decisions/NEW-ZERO', [
        'status' => 'included',
        'order_qty' => 0,
        'planned_maintain_qty' => 1,
    ])->assertOk()
        ->assertJsonPath('data.order_qty', 0);

    $this->assertDatabaseHas('plamod_restock_sku_decisions', [
        'sku' => 'NEW-ZERO',
        'status' => 'included',
        'order_qty' => 0,
    ]);

    $proposal = $this->getJson('/api/v1/plamod/restock/proposal')->assertOk();
    expect(collect($proposal->json('data.new_products'))->firstWhere('sku', 'NEW-ZERO')['order_qty'])
        ->toBe(0);
    expect(collect(app(\App\Services\Plamod\PlamodRestockCartLineBuilder::class)->buildLines())->pluck('sku'))
        ->not->toContain('NEW-ZERO');
    expect(collect(app(\App\Services\Plamod\PlamodRestockCartLineBuilder::class)->buildLines(includeZeroIncludedNew: true)))
        ->toContain([
            'sku' => 'NEW-ZERO',
            'qty' => 0,
            'product_name' => 'Zero Qty SKU',
            'source' => 'new',
        ]);
});

it('stores later decision and excludes it from undecided meta count', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'NEW-LATER',
        'product_name' => 'Later SKU',
        'price_stock' => '12.00',
        'last_seen_at' => now(),
    ]);
    PlamodInstockItem::query()->create([
        'sku' => 'NEW-OPEN',
        'product_name' => 'Open SKU',
        'price_stock' => '13.00',
        'last_seen_at' => now(),
    ]);

    $this->putJson('/api/v1/plamod/restock/decisions/NEW-LATER', [
        'status' => 'later',
    ])->assertOk();

    $this->assertDatabaseHas('plamod_restock_sku_decisions', [
        'sku' => 'NEW-LATER',
        'status' => 'later',
        'order_qty' => null,
    ]);

    $res = $this->getJson('/api/v1/plamod/restock/proposal')->assertOk();
    expect($res->json('data.meta.later_new_count'))->toBe(1);
    expect($res->json('data.meta.undecided_new_count'))->toBe(1);

    $laterRow = collect($res->json('data.new_products'))->firstWhere('sku', 'NEW-LATER');
    expect($laterRow['status'])->toBe('later');
});

it('bulk includes and dismisses new skus', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'BULK-1',
        'product_name' => 'Bulk One',
        'price_stock' => '10.00',
        'last_seen_at' => now(),
    ]);
    PlamodInstockItem::query()->create([
        'sku' => 'BULK-2',
        'product_name' => 'Bulk Two',
        'price_stock' => '11.00',
        'last_seen_at' => now(),
    ]);

    $this->postJson('/api/v1/plamod/restock/decisions/bulk', [
        'skus' => ['BULK-1', 'BULK-2'],
        'status' => 'included',
        'order_qty' => 2,
        'planned_maintain_qty' => 4,
    ])->assertOk()
        ->assertJsonPath('data.updated', 2);

    $this->assertDatabaseHas('plamod_restock_sku_decisions', [
        'sku' => 'BULK-1',
        'status' => 'included',
        'order_qty' => 2,
    ]);
    $this->assertDatabaseHas('plamod_restock_planned_maintain', [
        'sku' => 'BULK-2',
        'maintain_qty' => 4,
    ]);

    $this->postJson('/api/v1/plamod/restock/decisions/bulk', [
        'skus' => ['BULK-1'],
        'status' => 'dismissed',
    ])->assertOk()
        ->assertJsonPath('data.updated', 1);

    $this->assertDatabaseHas('plamod_restock_sku_decisions', [
        'sku' => 'BULK-1',
        'status' => 'dismissed',
    ]);
});

it('creates draft purchase order with existing and catalog-less lines', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'DRAFT-EXIST',
        'product_name' => 'Existing Draft Line',
        'price_stock' => '11.00',
        'last_seen_at' => now(),
    ]);
    PlamodInstockItem::query()->create([
        'sku' => 'DRAFT-NEW',
        'product_name' => 'New Draft Line',
        'price_stock' => '13.00',
        'barcode' => '1234567890123',
        'last_seen_at' => now(),
    ]);

    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'DRAFT-EXIST',
        'description' => 'Existing Draft Line',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 4,
    ]);

    PlamodRestockSkuDecision::query()->create([
        'sku' => 'DRAFT-NEW',
        'status' => 'included',
        'order_qty' => 2,
    ]);

    \App\Models\PlamodRestockPlannedMaintain::query()->create([
        'sku' => 'DRAFT-NEW',
        'maintain_qty' => 5,
    ]);

    $res = $this->postJson('/api/v1/plamod/restock/draft-purchase-order');
    $res->assertOk();

    $uuid = (string) $res->json('data.purchase_order_uuid');
    $po = PurchaseOrder::query()->where('uuid', '=', $uuid)->firstOrFail();
    expect($po->vendor)->toBe('Plamod');

    $items = PurchaseOrderItem::query()->where('purchase_order_id', '=', $po->id)->orderBy('sku')->get();
    expect($items)->toHaveCount(2);

    $existingLine = $items->firstWhere('sku', '=', 'DRAFT-EXIST');
    expect($existingLine?->product_id)->toBe($product->id);
    expect($existingLine?->qty_ordered)->toBe(4);

    $newLine = $items->firstWhere('sku', '=', 'DRAFT-NEW');
    expect($newLine?->product_id)->toBeNull();
    expect($newLine?->product_name)->toBe('New Draft Line');
    expect($newLine?->qty_ordered)->toBe(2);
});

it('shows not arrived on draft PO detail using the same formula as restock proposal', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'MP-RPR',
        'product_name' => 'Seepage line wiper pen (Red)',
        'price_stock' => '3.99',
        'last_seen_at' => now(),
    ]);

    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'MP-RPR',
        'description' => 'Seepage line wiper pen (Red)',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 5,
    ]);

    $orderedOpenPo = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'ordered_date' => '2026-04-01',
        'received_date' => null,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $orderedOpenPo->id,
        'product_id' => $product->id,
        'sku' => 'MP-RPR',
        'vendor' => 'Plamod',
        'qty_ordered' => 4,
    ]);

    $res = $this->postJson('/api/v1/plamod/restock/draft-purchase-order');
    $res->assertOk();

    $uuid = (string) $res->json('data.purchase_order_uuid');
    $detail = $this->getJson("/api/v1/purchase-orders/{$uuid}");
    $detail->assertOk();
    $detail->assertJsonPath('data.items.0.sku', 'MP-RPR');
    $detail->assertJsonPath('data.items.0.not_arrived', 4);
    $detail->assertJsonPath('data.items.0.reorder', 1);
    $detail->assertJsonPath('data.items.0.qty_ordered', 1);
});

it('queues plamod in-stock sync job', function (): void {
    Queue::fake();

    Http::fake([
        'http://plamod_scraper:3001/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /download-zip',
                'POST /export-preorders-csv',
                'POST /export-manufacturer-preorders-csv',
                'POST /export-manufacturer-instock-merged',
                'GET /instock-export-progress',
                'POST /list-manufacturer-preorders-filters',
                'POST /search-retailer-preorders',
                'POST /restock-add-to-cart',
                'POST /restock-verify-cart',
                'GET /restock-cart-progress',
            ],
        ], 200),
    ]);

    $res = $this->postJson('/api/v1/plamod/restock/sync');
    $res->assertOk()->assertJsonPath('data.ok', true);

    Queue::assertPushed(\App\Jobs\Plamod\SyncPlamodInstockJob::class);
});

it('reports instock sync status with scraper progress while running', function (): void {
    PlamodInstockSyncLog::query()->create([
        'status' => 'running',
        'started_at' => now()->subMinute(),
        'counts_json' => [],
    ]);

    Http::fake([
        'http://plamod_scraper:3001/instock-export-progress' => Http::response([
            'ok' => true,
            'active' => true,
            'phase' => 'export',
            'filters_total' => 49,
            'filters_processed' => 12,
            'current_filter' => '30 Minutes Label',
        ], 200),
    ]);

    $this->getJson('/api/v1/plamod/restock/sync-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.counts.phase', 'export')
        ->assertJsonPath('data.counts.filters_processed', 12)
        ->assertJsonPath('data.counts.filters_total', 49)
        ->assertJsonPath('data.counts.current_filter', '30 Minutes Label');
});

it('reports instock sync status', function (): void {
    PlamodInstockSyncLog::query()->create([
        'status' => 'completed',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'duration_ms' => 1000,
        'counts_json' => ['rows_upserted' => 12],
    ]);

    $this->getJson('/api/v1/plamod/restock/sync-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.counts.rows_upserted', 12);
});

it('persists reorder override for existing skus and uses it in proposal totals', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'OVERRIDE-1',
        'product_name' => 'Override One',
        'price_stock' => '10.00',
        'last_seen_at' => now(),
    ]);

    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'OVERRIDE-1',
        'description' => 'Override One',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 4,
    ]);

    $this->putJson('/api/v1/plamod/restock/reorder-overrides/OVERRIDE-1', [
        'reorder_qty' => 2,
    ])->assertOk()
        ->assertJsonPath('data.reorder_qty', 2)
        ->assertJsonPath('data.is_overridden', true);

    $this->assertDatabaseHas('plamod_restock_reorder_overrides', [
        'sku' => 'OVERRIDE-1',
        'reorder_qty' => 2,
    ]);

    $proposal = $this->getJson('/api/v1/plamod/restock/proposal')->assertOk();
    $proposal->assertJsonPath('data.existing.0.sku', 'OVERRIDE-1');
    $proposal->assertJsonPath('data.existing.0.reorder_qty', 4);
    $proposal->assertJsonPath('data.existing.0.proposed_qty', 2);
    $proposal->assertJsonPath('data.existing.0.is_reorder_overridden', true);
    $proposal->assertJsonPath('data.totals.unique_products', 1);
    $proposal->assertJsonPath('data.totals.units', 2);
    $proposal->assertJsonPath('data.totals.product', '20.00');
    $proposal->assertJsonPath('data.totals.landed', '21.00');
    $proposal->assertJsonPath('data.totals.existing.unique_products', 1);
    $proposal->assertJsonPath('data.totals.existing.units', 2);
    $proposal->assertJsonPath('data.totals.existing.landed', '21.00');
    $proposal->assertJsonPath('data.totals.new_products.unique_products', 0);
    $proposal->assertJsonPath('data.totals.new_products.units', 0);
    $proposal->assertJsonPath('data.totals.new_products.landed', '0.00');

    $this->putJson('/api/v1/plamod/restock/reorder-overrides/OVERRIDE-1', [
        'reorder_qty' => null,
    ])->assertOk()
        ->assertJsonPath('data.is_overridden', false);

    $this->assertDatabaseMissing('plamod_restock_reorder_overrides', [
        'sku' => 'OVERRIDE-1',
    ]);
});

it('uses reorder override qty when creating draft purchase order', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'OVERRIDE-PO',
        'product_name' => 'Override PO Line',
        'price_stock' => '11.00',
        'last_seen_at' => now(),
    ]);

    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'OVERRIDE-PO',
        'description' => 'Override PO Line',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 5,
    ]);

    $this->putJson('/api/v1/plamod/restock/reorder-overrides/OVERRIDE-PO', [
        'reorder_qty' => 1,
    ])->assertOk();

    $res = $this->postJson('/api/v1/plamod/restock/draft-purchase-order');
    $res->assertOk();

    $uuid = (string) $res->json('data.purchase_order_uuid');
    $po = PurchaseOrder::query()->where('uuid', '=', $uuid)->firstOrFail();
    $item = PurchaseOrderItem::query()
        ->where('purchase_order_id', '=', $po->id)
        ->where('sku', '=', 'OVERRIDE-PO')
        ->firstOrFail();

    expect($item->qty_ordered)->toBe(1);
});

it('queues plamod restock cart run when proposal has order lines', function (): void {
    Queue::fake();

    PlamodInstockItem::query()->create([
        'sku' => 'CART-1',
        'product_name' => 'Cart One',
        'price_stock' => '10.00',
        'last_seen_at' => now(),
    ]);

    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'CART-1',
        'description' => 'Cart One',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 3,
    ]);

    Http::fake([
        'http://plamod_scraper:3001/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /export-preorders-csv',
                'POST /export-manufacturer-preorders-csv',
                'POST /export-manufacturer-instock-merged',
                'POST /list-manufacturer-preorders-filters',
                'POST /search-retailer-preorders',
                'POST /restock-add-to-cart',
                'POST /restock-verify-cart',
                'GET /restock-cart-progress',
            ],
        ], 200),
    ]);

    $res = $this->postJson('/api/v1/plamod/restock/cart-run', ['skus' => ['CART-1']]);
    $res->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.line_count', 1);

    Queue::assertPushed(\App\Jobs\Plamod\SyncPlamodRestockCartJob::class);

    $run = PlamodRestockCartRun::query()->latest('id')->firstOrFail();
    expect($run->counts_json['requested_lines'][0])->toMatchArray([
        'sku' => 'CART-1',
        'qty' => 3,
    ]);
});

it('rejects plamod restock cart run when no order lines qualify', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'CART-ZERO',
        'product_name' => 'Zero reorder',
        'price_stock' => '10.00',
        'last_seen_at' => now(),
    ]);

    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'CART-ZERO',
        'description' => 'Zero reorder',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 0,
    ]);

    Http::fake([
        'http://plamod_scraper:3001/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /export-preorders-csv',
                'POST /export-manufacturer-preorders-csv',
                'POST /export-manufacturer-instock-merged',
                'POST /list-manufacturer-preorders-filters',
                'POST /search-retailer-preorders',
                'POST /restock-add-to-cart',
                'POST /restock-verify-cart',
                'GET /restock-cart-progress',
            ],
        ], 200),
    ]);

    $this->postJson('/api/v1/plamod/restock/cart-run', ['skus' => ['CART-ZERO']])
        ->assertStatus(422)
        ->assertJsonPath('data.ok', false);
});

it('rejects plamod restock cart run when skus array is missing', function (): void {
    Http::fake([
        'http://plamod_scraper:3001/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /export-preorders-csv',
                'POST /export-manufacturer-preorders-csv',
                'POST /export-manufacturer-instock-merged',
                'POST /list-manufacturer-preorders-filters',
                'POST /search-retailer-preorders',
                'POST /restock-add-to-cart',
                'POST /restock-verify-cart',
                'GET /restock-cart-progress',
            ],
        ], 200),
    ]);

    $this->postJson('/api/v1/plamod/restock/cart-run', [])
        ->assertStatus(422);
});

it('rejects plamod restock cart run when selected sku is not cart eligible', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'CART-BAD',
        'product_name' => 'Not eligible',
        'price_stock' => '10.00',
        'last_seen_at' => now(),
    ]);

    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'CART-BAD',
        'description' => 'Not eligible',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 0,
    ]);

    Http::fake([
        'http://plamod_scraper:3001/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /export-preorders-csv',
                'POST /export-manufacturer-preorders-csv',
                'POST /export-manufacturer-instock-merged',
                'POST /list-manufacturer-preorders-filters',
                'POST /search-retailer-preorders',
                'POST /restock-add-to-cart',
                'POST /restock-verify-cart',
                'GET /restock-cart-progress',
            ],
        ], 200),
    ]);

    $this->postJson('/api/v1/plamod/restock/cart-run', ['skus' => ['CART-BAD']])
        ->assertStatus(422)
        ->assertJsonPath('data.ok', false);
});

it('queues a zero-qty included new sku so fix mismatches can remove its cart line', function (): void {
    Queue::fake();
    PlamodInstockItem::query()->create([
        'sku' => 'CART-NEW-ZERO',
        'product_name' => 'Included zero qty',
        'price_stock' => '10.00',
        'last_seen_at' => now(),
    ]);
    PlamodRestockSkuDecision::query()->create([
        'sku' => 'CART-NEW-ZERO',
        'status' => 'included',
        'order_qty' => 0,
    ]);

    Http::fake([
        'http://plamod_scraper:3001/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /restock-add-to-cart',
                'POST /restock-verify-cart',
                'GET /restock-cart-progress',
            ],
        ], 200),
    ]);

    $this->postJson('/api/v1/plamod/restock/cart-run', ['skus' => ['CART-NEW-ZERO']])
        ->assertOk()
        ->assertJsonPath('data.line_count', 1);

    $run = PlamodRestockCartRun::query()->latest('id')->firstOrFail();
    expect($run->counts_json['requested_lines'][0]['qty'] ?? null)->toBe(0);
    Queue::assertPushed(\App\Jobs\Plamod\SyncPlamodRestockCartJob::class);
});

it('queues plamod restock cart run for a subset of skus', function (): void {
    Queue::fake();

    foreach (['CART-A', 'CART-B', 'CART-C'] as $sku) {
        PlamodInstockItem::query()->create([
            'sku' => $sku,
            'product_name' => $sku,
            'price_stock' => '10.00',
            'last_seen_at' => now(),
        ]);

        Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'sku' => $sku,
            'description' => $sku,
            'vendor' => 'Plamod',
            'available_qty' => 0,
            'maintain_qty' => 2,
        ]);
    }

    Http::fake([
        'http://plamod_scraper:3001/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /export-preorders-csv',
                'POST /export-manufacturer-preorders-csv',
                'POST /export-manufacturer-instock-merged',
                'POST /list-manufacturer-preorders-filters',
                'POST /search-retailer-preorders',
                'POST /restock-add-to-cart',
                'POST /restock-verify-cart',
                'GET /restock-cart-progress',
            ],
        ], 200),
    ]);

    $this->postJson('/api/v1/plamod/restock/cart-run', ['skus' => ['CART-A', 'CART-C']])
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.line_count', 2);

    Queue::assertPushed(\App\Jobs\Plamod\SyncPlamodRestockCartJob::class);
});

it('rejects a second cart run while one is active', function (): void {
    PlamodRestockCartRun::query()->create([
        'status' => 'running',
        'started_at' => now(),
        'counts_json' => ['phase' => 'adding'],
    ]);

    $this->postJson('/api/v1/plamod/restock/cart-run', ['skus' => ['CART-A']])
        ->assertStatus(422)
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.error_message', 'A PLAMOD cart run is already active.');
});

it('reports plamod restock cart run status with verification report when completed', function (): void {
    PlamodRestockCartRun::query()->create([
        'status' => 'completed',
        'started_at' => now()->subMinutes(2),
        'finished_at' => now(),
        'duration_ms' => 120000,
        'counts_json' => [
            'phase' => 'completed',
            'all_verified' => false,
            'summary' => [
                'requested_lines' => 2,
                'verified' => 1,
                'partial' => 1,
                'missing' => 0,
                'add_failed' => 0,
                'all_verified' => false,
            ],
            'report' => [
                'summary' => [
                    'requested_lines' => 2,
                    'verified' => 1,
                    'partial' => 1,
                    'missing' => 0,
                    'add_failed' => 0,
                    'all_verified' => false,
                ],
                'lines' => [
                    [
                        'sku' => 'CART-A',
                        'requested_qty' => 2,
                        'added_qty' => 2,
                        'cart_qty_after' => 2,
                        'verification_status' => 'verified',
                    ],
                    [
                        'sku' => 'CART-B',
                        'requested_qty' => 1,
                        'added_qty' => 0,
                        'cart_qty_after' => 0,
                        'verification_status' => 'partial',
                    ],
                ],
            ],
        ],
        'error_summary' => 'Cart verification incomplete: 1/2 verified, 1 partial, 0 missing, 0 add failed.',
    ]);

    $this->getJson('/api/v1/plamod/restock/cart-run-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.all_verified', false)
        ->assertJsonPath('data.summary.verified', 1)
        ->assertJsonPath('data.report.lines.0.sku', 'CART-A')
        ->assertJsonPath('data.report.lines.1.verification_status', 'partial')
        ->assertJsonPath('data.error_summary', 'Cart verification incomplete: 1/2 verified, 1 partial, 0 missing, 0 add failed.');
});

it('reports plamod restock cart run progress while running', function (): void {
    PlamodRestockCartRun::query()->create([
        'status' => 'completed',
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
        'counts_json' => [
            'report' => [
                'lines' => [
                    [
                        'sku' => 'ORDER-1',
                        'error_message' => 'Requested 2 but PLAMOD MOQ is 3.',
                    ],
                ],
            ],
        ],
    ]);
    PlamodRestockCartRun::query()->create([
        'status' => 'running',
        'started_at' => now()->subMinute(),
        'counts_json' => [
            'phase' => 'starting',
            'items_total' => 5,
            'items_processed' => 0,
        ],
    ]);
    expect(app(\App\Services\Plamod\PlamodRestockCartRunLogger::class)->latestLineErrorMessages())
        ->toBe(['ORDER-1' => 'Requested 2 but PLAMOD MOQ is 3.']);

    Http::fake([
        'http://plamod_scraper:3001/restock-cart-progress' => Http::response([
            'ok' => true,
            'active' => true,
            'phase' => 'adding',
            'items_total' => 5,
            'items_processed' => 2,
            'current_sku' => '5068381',
        ], 200),
    ]);

    $this->getJson('/api/v1/plamod/restock/cart-run-status')
        ->assertOk()
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.counts.phase', 'adding')
        ->assertJsonPath('data.counts.items_processed', 2)
        ->assertJsonPath('data.counts.current_sku', '5068381');
});

it('rechecks plamod restock cart verification against latest run report', function (): void {
    PlamodRestockCartRun::query()->create([
        'status' => 'completed',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'duration_ms' => 120000,
        'counts_json' => [
            'phase' => 'completed',
            'all_verified' => false,
            'report' => [
                'cart_before' => ['RECHECK-1' => 0],
                'summary' => [
                    'requested_lines' => 1,
                    'verified' => 0,
                    'missing' => 1,
                    'all_verified' => false,
                ],
                'lines' => [
                    [
                        'sku' => 'RECHECK-1',
                        'requested_qty' => 2,
                        'add_status' => 'added',
                        'verification_status' => 'missing',
                        'cart_qty_after' => 0,
                    ],
                ],
            ],
        ],
        'error_summary' => 'Cart verification incomplete: 0/1 verified, 0 over-added, 0 partial, 1 missing, 0 add failed.',
    ]);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('restockVerifyCart')
        ->once()
        ->with([
            'cart_before' => ['RECHECK-1' => 0],
            'lines' => [
                [
                    'sku' => 'RECHECK-1',
                    'requested_qty' => 2,
                    'add_status' => 'added',
                    'verification_status' => 'missing',
                    'cart_qty_after' => 0,
                ],
            ],
        ])
        ->andReturn([
            'ok' => true,
            'duration_ms' => 4500,
            'report' => [
                'cart_before' => ['RECHECK-1' => 0],
                'cart_after' => ['RECHECK-1' => 2],
                'summary' => [
                    'requested_lines' => 1,
                    'verified' => 1,
                    'missing' => 0,
                    'all_verified' => true,
                ],
                'lines' => [
                    [
                        'sku' => 'RECHECK-1',
                        'requested_qty' => 2,
                        'add_status' => 'added',
                        'verification_status' => 'verified',
                        'cart_qty_after' => 2,
                    ],
                ],
            ],
        ]);
    app()->instance(PlamodScraper::class, $scraper);

    $this->postJson('/api/v1/plamod/restock/cart-run-recheck')
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.all_verified', true)
        ->assertJsonPath('data.report.lines.0.verification_status', 'verified');

    $run = PlamodRestockCartRun::query()->orderByDesc('id')->first();
    expect($run?->error_summary)->toBeNull();
    expect($run?->counts_json['all_verified'] ?? null)->toBeTrue();
});

it('rejects plamod restock cart recheck while a run is active', function (): void {
    PlamodRestockCartRun::query()->create([
        'status' => 'running',
        'started_at' => now(),
        'counts_json' => ['phase' => 'adding'],
    ]);

    $this->postJson('/api/v1/plamod/restock/cart-run-recheck')
        ->assertStatus(422)
        ->assertJsonPath('data.ok', false);
});

it('verifies full plamod restock order against live cart', function (): void {
    PlamodInstockItem::query()->create([
        'sku' => 'ORDER-1',
        'product_name' => 'Order One',
        'price_stock' => '10.00',
        'last_seen_at' => now(),
    ]);

    Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'ORDER-1',
        'description' => 'Order One',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 2,
    ]);
    PlamodInstockItem::query()->create([
        'sku' => 'ORDER-ZERO',
        'product_name' => 'Order Zero',
        'price_stock' => '8.00',
        'last_seen_at' => now(),
    ]);
    PlamodRestockSkuDecision::query()->create([
        'sku' => 'ORDER-ZERO',
        'status' => 'included',
        'order_qty' => 0,
    ]);

    PlamodRestockCartRun::query()->create([
        'status' => 'completed',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'counts_json' => [
            'report' => [
                'lines' => [
                    [
                        'sku' => 'ORDER-1',
                        'verification_status' => 'add_failed',
                        'error_message' => 'Requested 2 but PLAMOD MOQ is 3.',
                    ],
                ],
            ],
        ],
    ]);

    Http::fake([
        'http://plamod_scraper:3001/health' => Http::response([
            'ok' => true,
            'routes' => [
                'POST /export-preorders-csv',
                'POST /export-manufacturer-preorders-csv',
                'POST /export-manufacturer-instock-merged',
                'POST /list-manufacturer-preorders-filters',
                'POST /search-retailer-preorders',
                'POST /restock-add-to-cart',
                'POST /restock-verify-cart',
                'GET /restock-cart-progress',
            ],
        ], 200),
    ]);

    $scraper = Mockery::mock(\App\Services\Products\Http\PlamodScraper::class);
    $scraper->shouldReceive('restockVerifyCart')
        ->once()
        ->withArgs(function (array $payload): bool {
            expect($payload['scope'] ?? null)->toBe('full_order');
            expect($payload['cart_before'] ?? null)->toBe([]);
            expect($payload['lines'] ?? null)->toHaveCount(2);
            expect($payload['lines'][0]['sku'] ?? null)->toBe('ORDER-1');
            expect($payload['lines'][0]['requested_qty'] ?? null)->toBe(2);
            expect($payload['lines'][0]['add_status'] ?? null)->toBe('order_verify');
            expect($payload['lines'][0]['error_message'] ?? null)
                ->toBe('Requested 2 but PLAMOD MOQ is 3.');
            expect($payload['lines'][1]['sku'] ?? null)->toBe('ORDER-ZERO');
            expect($payload['lines'][1]['requested_qty'] ?? null)->toBe(0);

            return true;
        })
        ->andReturn([
            'ok' => true,
            'duration_ms' => 1200,
            'report' => [
                'scope' => 'full_order',
                'verified_at' => now()->toIso8601String(),
                'cart_after' => ['EXTRA-1' => 1],
                'preorder_arrived' => ['ORDER-1' => 3],
                'summary' => [
                    'requested_lines' => 2,
                    'verified' => 0,
                    'missing' => 1,
                    'partial' => 0,
                    'over_added' => 1,
                    'add_failed' => 0,
                    'already_satisfied' => 0,
                    'all_verified' => false,
                    'extra_cart_lines' => 1,
                    'order_matches_cart' => false,
                ],
                'extra_cart_lines' => [
                    ['sku' => 'EXTRA-1', 'cart_qty' => 1],
                ],
                'lines' => [
                    [
                        'sku' => 'ORDER-1',
                        'requested_qty' => 2,
                        'verification_status' => 'missing',
                        'cart_qty_after' => 0,
                        'preorder_arrived_qty' => 3,
                    ],
                    [
                        'sku' => 'ORDER-ZERO',
                        'requested_qty' => 0,
                        'verification_status' => 'over_added',
                        'cart_qty_after' => 1,
                        'preorder_arrived_qty' => 0,
                    ],
                ],
            ],
        ]);
    app()->instance(\App\Services\Products\Http\PlamodScraper::class, $scraper);

    $this->postJson('/api/v1/plamod/restock/order-verify')
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.order_matches_cart', false)
        ->assertJsonPath('data.report.extra_cart_lines.0.sku', 'EXTRA-1')
        ->assertJsonPath('data.report.preorder_arrived.ORDER-1', 3)
        ->assertJsonPath('data.report.lines.0.preorder_arrived_qty', 3)
        ->assertJsonPath('data.report.lines.0.error_message', 'Requested 2 but PLAMOD MOQ is 3.')
        ->assertJsonPath('data.report.lines.1.sku', 'ORDER-ZERO')
        ->assertJsonPath('data.report.lines.1.verification_status', 'over_added');

    $this->getJson('/api/v1/plamod/restock/order-verify')
        ->assertOk()
        ->assertJsonPath('data.report.lines.0.verification_status', 'missing')
        ->assertJsonPath('data.report.lines.0.error_message', 'Requested 2 but PLAMOD MOQ is 3.')
        ->assertJsonPath('data.summary.extra_cart_lines', 1);
});

it('rejects full plamod order verify while cart automation is active', function (): void {
    PlamodRestockCartRun::query()->create([
        'status' => 'running',
        'started_at' => now(),
        'counts_json' => ['phase' => 'adding'],
    ]);

    $this->postJson('/api/v1/plamod/restock/order-verify')
        ->assertStatus(422)
        ->assertJsonPath('data.ok', false);
});
