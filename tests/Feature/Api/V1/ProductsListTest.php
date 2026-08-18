<?php

declare(strict_types=1);

use App\Models\ProductSellingPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\UploadedFile;

it('lists imported products', function (): void {
    $csv = implode("\n", [
        'SKU,BARCODE,PRODUCT DESCRIPTION,TYPE,PRICE,ORDER,FILLED,EXTENDED',
        '5060358,4573102603586,HG 1/144 #13 Gundam Astray Blue Frame,HG,$10.13,2,2,$20.26',
    ]);

    $file = UploadedFile::fake()->createWithContent('products.csv', $csv, 'text/csv');

    $this->postJson('/api/v1/products/import', [
        'file' => $file,
    ])->assertOk();

    $response = $this->getJson('/api/v1/products');

    $response
        ->assertOk()
        ->assertJsonPath('data.0.sku', '5060358')
        ->assertJsonPath('data.0.barcode', '4573102603586')
        ->assertJsonPath('data.0.main_type', 'model kit')
        ->assertJsonPath('data.0.type', 'HG')
        ->assertJsonPath('data.0.vendor', 'Plamod');
});

it('filters products by main type', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'MT-1',
        'description' => 'Model kit product',
        'main_type' => 'model kit',
        'vendor' => 'Plamod',
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'MT-2',
        'description' => 'Tool product',
        'main_type' => 'tools',
        'vendor' => 'Plamod',
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&main_types[]=tools');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'MT-2')
        ->assertJsonMissing(['sku' => 'MT-1']);
});

it('filters products by empty main type', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'EMT-1',
        'description' => 'Empty main type product',
        'main_type' => '',
        'vendor' => 'Plamod',
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'EMT-2',
        'description' => 'Non-empty main type product',
        'main_type' => 'tools',
        'vendor' => 'Plamod',
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&main_types[]=__empty__');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'EMT-1')
        ->assertJsonMissing(['sku' => 'EMT-2']);
});

it('filters products by type', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'TYPE-1',
        'description' => 'Type one',
        'type' => 'HG',
        'vendor' => 'Plamod',
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'TYPE-2',
        'description' => 'Type two',
        'type' => 'TOOLS',
        'vendor' => 'Plamod',
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&types[]=TOOLS');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'TYPE-2')
        ->assertJsonMissing(['sku' => 'TYPE-1']);
});

it('filters products by vendor', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'VEND-1',
        'description' => 'Vendor A',
        'vendor' => 'Plamod',
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'VEND-2',
        'description' => 'Vendor B',
        'vendor' => 'Stedi',
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&vendors[]=Stedi');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'VEND-2')
        ->assertJsonMissing(['sku' => 'VEND-1']);
});

it('filters products by selling price range', function (): void {
    $low = \App\Models\Product::query()->create([
        'sku' => 'PRICE-LOW',
        'description' => 'Low price',
        'vendor' => 'Plamod',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $low->id,
        'product_uuid' => $low->uuid,
        'selling_price' => '5.00',
    ]);

    $mid = \App\Models\Product::query()->create([
        'sku' => 'PRICE-MID',
        'description' => 'Mid price',
        'vendor' => 'Plamod',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $mid->id,
        'product_uuid' => $mid->uuid,
        'selling_price' => '11.99',
    ]);

    $high = \App\Models\Product::query()->create([
        'sku' => 'PRICE-HIGH',
        'description' => 'High price',
        'vendor' => 'Plamod',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $high->id,
        'product_uuid' => $high->uuid,
        'selling_price' => '24.50',
    ]);

    \App\Models\Product::query()->create([
        'sku' => 'PRICE-NONE',
        'description' => 'No selling price',
        'vendor' => 'Plamod',
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&selling_price_min=10&selling_price_max=20');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'PRICE-MID')
        ->assertJsonMissing(['sku' => 'PRICE-LOW'])
        ->assertJsonMissing(['sku' => 'PRICE-HIGH'])
        ->assertJsonMissing(['sku' => 'PRICE-NONE']);
});

it('sorts products by selling price', function (): void {
    $p1 = \App\Models\Product::query()->create([
        'sku' => 'SELL-10',
        'description' => 'Selling 10',
        'vendor' => 'Plamod',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p1->id,
        'product_uuid' => $p1->uuid,
        'selling_price' => '10.00',
    ]);

    $p2 = \App\Models\Product::query()->create([
        'sku' => 'SELL-02',
        'description' => 'Selling 2',
        'vendor' => 'Plamod',
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p2->id,
        'product_uuid' => $p2->uuid,
        'selling_price' => '2.00',
    ]);

    \App\Models\Product::query()->create([
        'sku' => 'SELL-NULL',
        'description' => 'No selling price',
        'vendor' => 'Plamod',
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&sort_by=selling_price&sort_dir=asc');
    $res->assertOk();
    $res->assertJsonPath('data.0.sku', 'SELL-02');
    $res->assertJsonPath('data.1.sku', 'SELL-10');
    $res->assertJsonPath('data.2.sku', 'SELL-NULL');
});

it('sorts products by received date descending with nulls last', function (): void {
    $poOld = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-01-01',
    ]);
    $poNew = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-03-15',
    ]);

    $newest = \App\Models\Product::query()->create([
        'sku' => 'RCV-NEW',
        'description' => 'Newest received',
        'vendor' => 'Plamod',
    ]);
    $older = \App\Models\Product::query()->create([
        'sku' => 'RCV-OLD',
        'description' => 'Older received',
        'vendor' => 'Plamod',
    ]);
    $none = \App\Models\Product::query()->create([
        'sku' => 'RCV-NONE',
        'description' => 'No received PO',
        'vendor' => 'Plamod',
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poNew->id,
        'product_id' => $newest->id,
        'sku' => $newest->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
        'qty_received' => 1,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poOld->id,
        'product_id' => $older->id,
        'sku' => $older->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
        'qty_received' => 1,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&sort_by=received_date&sort_dir=desc');
    $res->assertOk();
    $res->assertJsonPath('data.0.sku', 'RCV-NEW');
    $res->assertJsonPath('data.0.received_date', '2026-03-15');
    $res->assertJsonPath('data.1.sku', 'RCV-OLD');
    $res->assertJsonPath('data.1.received_date', '2026-01-01');
    $res->assertJsonPath('data.2.sku', 'RCV-NONE');
    $res->assertJsonPath('data.2.received_date', null);
});

it('filters products by not-ready flag', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'READY-1',
        'description' => 'Ready product',
        'vendor' => 'Plamod',
        'is_ready' => true,
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'NOTREADY-1',
        'description' => 'Not ready product',
        'vendor' => 'Plamod',
        'is_ready' => false,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&missing[]=not_ready');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'NOTREADY-1')
        ->assertJsonMissing(['sku' => 'READY-1']);
});

it('filters products by ready flag', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'READY-FILTER-YES',
        'description' => 'Ready true product',
        'vendor' => 'Plamod',
        'is_ready' => true,
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'READY-FILTER-NO',
        'description' => 'Ready false product',
        'vendor' => 'Plamod',
        'is_ready' => false,
    ]);

    $readyRes = $this->getJson('/api/v1/products?per_page=100&ready=ready');
    $readyRes->assertOk()
        ->assertJsonPath('data.0.sku', 'READY-FILTER-YES')
        ->assertJsonMissing(['sku' => 'READY-FILTER-NO']);

    $notReadyRes = $this->getJson('/api/v1/products?per_page=100&ready=not_ready');
    $notReadyRes->assertOk()
        ->assertJsonPath('data.0.sku', 'READY-FILTER-NO')
        ->assertJsonMissing(['sku' => 'READY-FILTER-YES']);
});

it('validates ready filter values', function (): void {
    $this->getJson('/api/v1/products?ready=invalid')->assertStatus(422);
});

it('filters products by published on shopify flag', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'PUBLISHED-FILTER-YES',
        'description' => 'Published product',
        'vendor' => 'Plamod',
        'published_on_shopify' => true,
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'PUBLISHED-FILTER-NO',
        'description' => 'Not published product',
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
    ]);

    $publishedRes = $this->getJson('/api/v1/products?per_page=100&published=published');
    $publishedRes->assertOk()
        ->assertJsonPath('data.0.sku', 'PUBLISHED-FILTER-YES')
        ->assertJsonMissing(['sku' => 'PUBLISHED-FILTER-NO']);

    $notPublishedRes = $this->getJson('/api/v1/products?per_page=100&published=not_published');
    $notPublishedRes->assertOk()
        ->assertJsonPath('data.0.sku', 'PUBLISHED-FILTER-NO')
        ->assertJsonMissing(['sku' => 'PUBLISHED-FILTER-YES']);
});

it('validates published filter values', function (): void {
    $this->getJson('/api/v1/products?published=invalid')->assertStatus(422);
});

it('filters products by critical and discontinued product flags', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'FLAG-CRIT',
        'description' => 'Critical only',
        'vendor' => 'Plamod',
        'is_critical' => true,
        'is_discontinued' => false,
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'FLAG-DISC',
        'description' => 'Discontinued only',
        'vendor' => 'Plamod',
        'is_critical' => false,
        'is_discontinued' => true,
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'FLAG-BOTH',
        'description' => 'Critical and discontinued',
        'vendor' => 'Plamod',
        'is_critical' => true,
        'is_discontinued' => true,
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'FLAG-NONE',
        'description' => 'Neither flag',
        'vendor' => 'Plamod',
        'is_critical' => false,
        'is_discontinued' => false,
    ]);

    $criticalSkus = collect(
        $this->getJson('/api/v1/products?per_page=100&product_flags[]=critical')
            ->assertOk()
            ->json('data'),
    )->pluck('sku')->all();
    expect($criticalSkus)->toContain('FLAG-CRIT', 'FLAG-BOTH')
        ->not->toContain('FLAG-DISC', 'FLAG-NONE');

    $discontinuedSkus = collect(
        $this->getJson('/api/v1/products?per_page=100&product_flags[]=discontinued')
            ->assertOk()
            ->json('data'),
    )->pluck('sku')->all();
    expect($discontinuedSkus)->toContain('FLAG-DISC', 'FLAG-BOTH')
        ->not->toContain('FLAG-CRIT', 'FLAG-NONE');

    $eitherSkus = collect(
        $this->getJson('/api/v1/products?per_page=100&product_flags[]=critical&product_flags[]=discontinued')
            ->assertOk()
            ->json('data'),
    )->pluck('sku')->all();
    expect($eitherSkus)->toContain('FLAG-CRIT', 'FLAG-DISC', 'FLAG-BOTH')
        ->not->toContain('FLAG-NONE');
});

it('filters products by hazardous shipment product flag', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'FLAG-HAZ',
        'description' => 'Hazardous only',
        'vendor' => 'Plamod',
        'is_hazardous_shipment' => true,
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'FLAG-NO-HAZ',
        'description' => 'Not hazardous',
        'vendor' => 'Plamod',
        'is_hazardous_shipment' => false,
    ]);

    $skus = collect(
        $this->getJson('/api/v1/products?per_page=100&product_flags[]=hazardous_shipment')
            ->assertOk()
            ->json('data'),
    )->pluck('sku')->all();

    expect($skus)->toContain('FLAG-HAZ')->not->toContain('FLAG-NO-HAZ');
});

it('filters products by shipment method', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'SHIP-AIR',
        'description' => 'Air shipment',
        'vendor' => 'Plamod',
        'shipment_method' => 'air',
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'SHIP-SEA',
        'description' => 'Sea shipment',
        'vendor' => 'Plamod',
        'shipment_method' => 'sea',
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'SHIP-NONE',
        'description' => 'No shipment',
        'vendor' => 'Plamod',
        'shipment_method' => null,
    ]);

    $airSkus = collect(
        $this->getJson('/api/v1/products?per_page=100&shipment_methods[]=air')
            ->assertOk()
            ->json('data'),
    )->pluck('sku')->all();
    expect($airSkus)->toContain('SHIP-AIR')->not->toContain('SHIP-SEA', 'SHIP-NONE');

    $eitherSkus = collect(
        $this->getJson('/api/v1/products?per_page=100&shipment_methods[]=air&shipment_methods[]=sea')
            ->assertOk()
            ->json('data'),
    )->pluck('sku')->all();
    expect($eitherSkus)->toContain('SHIP-AIR', 'SHIP-SEA')->not->toContain('SHIP-NONE');
});

it('validates product_flags filter values', function (): void {
    $this->getJson('/api/v1/products?product_flags[]=invalid')->assertStatus(422);
});

it('validates shipment_methods filter values', function (): void {
    $this->getJson('/api/v1/products?shipment_methods[]=invalid')->assertStatus(422);
});

it('filters products by available qty = 0', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'AVAIL-0',
        'description' => 'Out of stock',
        'vendor' => 'Plamod',
        'available_qty' => 0,
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'AVAIL-5',
        'description' => 'In stock',
        'vendor' => 'Plamod',
        'available_qty' => 5,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&missing[]=available_zero');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'AVAIL-0')
        ->assertJsonMissing(['sku' => 'AVAIL-5']);
});

it('filters products by maintain qty empty', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'MAINT-EMPTY',
        'description' => 'Missing maintain qty',
        'vendor' => 'Plamod',
        'maintain_qty' => null,
    ]);
    \App\Models\Product::query()->create([
        'sku' => 'MAINT-SET',
        'description' => 'Has maintain qty',
        'vendor' => 'Plamod',
        'maintain_qty' => 3,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&missing[]=maintain_empty');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'MAINT-EMPTY')
        ->assertJsonMissing(['sku' => 'MAINT-SET']);
});

it('returns filter options for vendor/type/grade/scale/series', function (): void {
    \App\Models\Product::query()->create([
        'sku' => 'OPTS-1',
        'description' => 'Options A',
        'vendor' => 'Stedi',
        'main_type' => 'tools',
        'type' => 'TOOLS',
        'grade' => 'HG',
        'scale' => '1/144',
        'series' => 'Gundam Wing',
    ]);

    $res = $this->getJson('/api/v1/products/filter-options');
    $res->assertOk();
    $res->assertJsonPath('data.main_types.0', 'tools');
    $res->assertJsonPath('data.types.0', 'TOOLS');
    $res->assertJsonPath('data.vendors.0', 'Stedi');
    $res->assertJsonPath('data.grades.0', 'HG');
    $res->assertJsonPath('data.scales.0', '1/144');
    $res->assertJsonPath('data.series.0', 'Gundam Wing');
});

it('filters products by numeric available, not-arrived, and reorder fields', function (): void {
    $poOpen = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
    ]);

    $match = \App\Models\Product::query()->create([
        'sku' => 'NUM-FILTER-MATCH',
        'description' => 'Matches numeric filters',
        'vendor' => 'Plamod',
        'available_qty' => 3,
        'maintain_qty' => 10,
    ]);
    $other = \App\Models\Product::query()->create([
        'sku' => 'NUM-FILTER-OTHER',
        'description' => 'Does not match numeric filters',
        'vendor' => 'Plamod',
        'available_qty' => 3,
        'maintain_qty' => 10,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poOpen->id,
        'product_id' => $match->id,
        'sku' => $match->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 2,
        'qty_received' => 0,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poOpen->id,
        'product_id' => $other->id,
        'sku' => $other->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
        'qty_received' => 0,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&available_min=3&available_max=3&not_arrived=2&reorder=5');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'NUM-FILTER-MATCH')
        ->assertJsonMissing(['sku' => 'NUM-FILTER-OTHER']);
});

it('filters products by available qty range', function (): void {
    $low = \App\Models\Product::query()->create([
        'sku' => 'AVAIL-LOW',
        'description' => 'Low available',
        'vendor' => 'Plamod',
        'available_qty' => 2,
    ]);
    $mid = \App\Models\Product::query()->create([
        'sku' => 'AVAIL-MID',
        'description' => 'Mid available',
        'vendor' => 'Plamod',
        'available_qty' => 8,
    ]);
    $high = \App\Models\Product::query()->create([
        'sku' => 'AVAIL-HIGH',
        'description' => 'High available',
        'vendor' => 'Plamod',
        'available_qty' => 20,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&available_min=5&available_max=10');
    $res->assertOk()
        ->assertJsonPath('data.0.sku', 'AVAIL-MID')
        ->assertJsonMissing(['sku' => 'AVAIL-LOW'])
        ->assertJsonMissing(['sku' => 'AVAIL-HIGH']);
});

it('validates numeric product list filters as non-negative integers', function (): void {
    $this->getJson('/api/v1/products?available_min=-1')->assertStatus(422);
    $this->getJson('/api/v1/products?not_arrived=-1')->assertStatus(422);
    $this->getJson('/api/v1/products?reorder=-1')->assertStatus(422);
});

it('filters products by reorder greater than or equal to one', function (): void {
    $poOpen = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
    ]);

    $needsReorder = \App\Models\Product::query()->create([
        'sku' => 'REORDER-GTE1-MATCH',
        'description' => 'Reorder greater than one',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 5,
    ]);
    $reorderExactlyOne = \App\Models\Product::query()->create([
        'sku' => 'REORDER-GTE1-EXACT',
        'description' => 'Reorder exactly one',
        'vendor' => 'Plamod',
        'available_qty' => 3,
        'maintain_qty' => 5,
    ]);
    $noReorder = \App\Models\Product::query()->create([
        'sku' => 'REORDER-GTE1-NOMATCH',
        'description' => 'Reorder zero',
        'vendor' => 'Plamod',
        'available_qty' => 4,
        'maintain_qty' => 5,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poOpen->id,
        'product_id' => $needsReorder->id,
        'sku' => $needsReorder->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 2,
        'qty_received' => 0,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poOpen->id,
        'product_id' => $reorderExactlyOne->id,
        'sku' => $reorderExactlyOne->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
        'qty_received' => 0,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poOpen->id,
        'product_id' => $noReorder->id,
        'sku' => $noReorder->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
        'qty_received' => 0,
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&reorder_gt_one=1');
    $res->assertOk()
        ->assertJsonFragment(['sku' => 'REORDER-GTE1-MATCH'])
        ->assertJsonFragment(['sku' => 'REORDER-GTE1-EXACT'])
        ->assertJsonMissing(['sku' => 'REORDER-GTE1-NOMATCH']);
});

it('sorts products by total ordered and total sold', function (): void {
    $poReceived = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-01-01',
    ]);

    $highOrderedLowSold = \App\Models\Product::query()->create([
        'sku' => 'SORT-TOTAL-1',
        'description' => 'Higher ordered lower sold',
        'vendor' => 'Plamod',
        'available_qty' => 4,
        'maintain_qty' => 10,
    ]);
    $lowOrderedHighSold = \App\Models\Product::query()->create([
        'sku' => 'SORT-TOTAL-2',
        'description' => 'Lower ordered higher sold',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 10,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poReceived->id,
        'product_id' => $highOrderedLowSold->id,
        'sku' => $highOrderedLowSold->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 5,
        'qty_received' => 5,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poReceived->id,
        'product_id' => $lowOrderedHighSold->id,
        'sku' => $lowOrderedHighSold->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 2,
        'qty_received' => 2,
    ]);

    $orderedAsc = $this->getJson('/api/v1/products?per_page=100&sort_by=total_ordered&sort_dir=asc');
    $orderedAsc->assertOk()
        ->assertJsonPath('data.0.sku', 'SORT-TOTAL-2')
        ->assertJsonPath('data.1.sku', 'SORT-TOTAL-1');

    $soldAsc = $this->getJson('/api/v1/products?per_page=100&sort_by=total_sold&sort_dir=asc');
    $soldAsc->assertOk()
        ->assertJsonPath('data.0.sku', 'SORT-TOTAL-1')
        ->assertJsonPath('data.1.sku', 'SORT-TOTAL-2');
});

it('sorts products by not-arrived and reorder', function (): void {
    $poOpen = PurchaseOrder::query()->create([
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
    ]);

    $low = \App\Models\Product::query()->create([
        'sku' => 'SORT-REORDER-LOW',
        'description' => 'Lower not-arrived/reorder',
        'vendor' => 'Plamod',
        'available_qty' => 2,
        'maintain_qty' => 4,
    ]);
    $high = \App\Models\Product::query()->create([
        'sku' => 'SORT-REORDER-HIGH',
        'description' => 'Higher not-arrived/reorder',
        'vendor' => 'Plamod',
        'available_qty' => 0,
        'maintain_qty' => 6,
    ]);

    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poOpen->id,
        'product_id' => $low->id,
        'sku' => $low->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 1,
        'qty_received' => 0,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $poOpen->id,
        'product_id' => $high->id,
        'sku' => $high->sku,
        'vendor' => 'Plamod',
        'qty_ordered' => 3,
        'qty_received' => 0,
    ]);

    $notArrivedAsc = $this->getJson('/api/v1/products?per_page=100&sort_by=not_arrived&sort_dir=asc');
    $notArrivedAsc->assertOk()
        ->assertJsonPath('data.0.sku', 'SORT-REORDER-LOW')
        ->assertJsonPath('data.1.sku', 'SORT-REORDER-HIGH');

    $reorderAsc = $this->getJson('/api/v1/products?per_page=100&sort_by=reorder&sort_dir=asc');
    $reorderAsc->assertOk()
        ->assertJsonPath('data.0.sku', 'SORT-REORDER-LOW')
        ->assertJsonPath('data.1.sku', 'SORT-REORDER-HIGH');
});

it('sorts products by demand (sold last 4 weeks)', function (): void {
    $low = \App\Models\Product::query()->create([
        'sku' => 'SORT-DEMAND-LOW',
        'description' => 'Lower 4-week sales',
        'vendor' => 'Plamod',
    ]);
    $high = \App\Models\Product::query()->create([
        'sku' => 'SORT-DEMAND-HIGH',
        'description' => 'Higher 4-week sales',
        'vendor' => 'Plamod',
    ]);

    \App\Models\ProductDemandDailyRollup::query()->create([
        'product_id' => $low->id,
        'sold_on' => now()->subDays(3)->toDateString(),
        'shopify_sold' => 2,
        'assumed_sold' => 0,
    ]);
    \App\Models\ProductDemandDailyRollup::query()->create([
        'product_id' => $high->id,
        'sold_on' => now()->subDays(2)->toDateString(),
        'shopify_sold' => 9,
        'assumed_sold' => 1,
    ]);

    $demandAsc = $this->getJson('/api/v1/products?per_page=100&sort_by=demand&sort_dir=asc&search=SORT-DEMAND');
    $demandAsc->assertOk()
        ->assertJsonPath('data.0.sku', 'SORT-DEMAND-LOW')
        ->assertJsonPath('data.0.sold_4w', 2)
        ->assertJsonPath('data.1.sku', 'SORT-DEMAND-HIGH')
        ->assertJsonPath('data.1.sold_4w', 10);

    $demandDesc = $this->getJson('/api/v1/products?per_page=100&sort_by=demand&sort_dir=desc&search=SORT-DEMAND');
    $demandDesc->assertOk()
        ->assertJsonPath('data.0.sku', 'SORT-DEMAND-HIGH')
        ->assertJsonPath('data.1.sku', 'SORT-DEMAND-LOW');
});

it('includes distinct eligible shopify order count on product rows', function (): void {
    $product = \App\Models\Product::query()->create([
        'sku' => 'SHOPIFY-ORDER-COUNT',
        'description' => 'Shopify order count product',
        'vendor' => 'Plamod',
    ]);

    foreach ([
        ['gid' => 'gid://shopify/Order/1001', 'name' => '#1001'],
        ['gid' => 'gid://shopify/Order/1002', 'name' => '#1002'],
        ['gid' => 'gid://shopify/Order/1003', 'name' => '#1003', 'cancelled_at' => now()],
        ['gid' => 'gid://shopify/Order/1004', 'name' => '#1004', 'display_financial_status' => 'VOIDED'],
    ] as $order) {
        \App\Models\Shopify\ShopifyOrder::query()->create($order);
    }

    foreach ([
        ['order_gid' => 'gid://shopify/Order/1001', 'line_gid' => 'gid://shopify/LineItem/1001-A'],
        ['order_gid' => 'gid://shopify/Order/1001', 'line_gid' => 'gid://shopify/LineItem/1001-B'],
        ['order_gid' => 'gid://shopify/Order/1002', 'line_gid' => 'gid://shopify/LineItem/1002-A'],
        ['order_gid' => 'gid://shopify/Order/1003', 'line_gid' => 'gid://shopify/LineItem/1003-A'],
        ['order_gid' => 'gid://shopify/Order/1004', 'line_gid' => 'gid://shopify/LineItem/1004-A'],
    ] as $line) {
        \App\Models\Shopify\ShopifyOrderLineItem::query()->create([
            ...$line,
            'sku' => $product->sku,
            'product_id' => $product->id,
            'quantity' => 1,
            'sold_on' => now()->toDateString(),
        ]);
    }

    $response = $this->getJson('/api/v1/products?search=SHOPIFY-ORDER-COUNT');

    $response->assertOk()
        ->assertJsonPath('data.0.sku', 'SHOPIFY-ORDER-COUNT')
        ->assertJsonPath('data.0.shopify_orders_count', 2);
});
