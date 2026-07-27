<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Products\LatestArrivalCatalogOrderService;
use App\Services\Products\LatestArrivalPushProductSortService;
use App\Services\Products\ProductTypeDerivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('orders latest arrival products by PO date then product sort within PO', function (): void {
    $service = new LatestArrivalCatalogOrderService(
        new LatestArrivalPushProductSortService(new ProductTypeDerivationService),
    );

    $oldPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120001',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-01-01',
    ]);
    $newPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120002',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-05-01',
    ]);

    $oldRg = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120011',
        'sku' => 'CAT-OLD-RG',
        'description' => 'RG old PO',
        'type' => 'RG',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);
    $newMg = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120012',
        'sku' => 'CAT-NEW-MG',
        'description' => 'MG new PO',
        'type' => 'MG',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);
    $newMgex = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120013',
        'sku' => 'CAT-NEW-MGEX',
        'description' => 'MGEX new PO',
        'type' => 'MGEX',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);

    foreach ([[$oldPo, $oldRg], [$newPo, $newMg], [$newPo, $newMgex]] as [$po, $product]) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $ordered = $service->orderedLatestArrivalProducts();

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $ordered))->toBe([
        'CAT-NEW-MGEX',
        'CAT-NEW-MG',
        'CAT-OLD-RG',
    ]);
});

it('ignores unreceived POs when grouping and ranking latest arrivals', function (): void {
    $service = new LatestArrivalCatalogOrderService(
        new LatestArrivalPushProductSortService(new ProductTypeDerivationService),
    );

    $receivedPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120091',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-06-03',
    ]);
    $unreceivedPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120092',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => null,
    ]);

    $mega = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120093',
        'sku' => 'CAT-MEGA-RECEIVED',
        'description' => 'Mega Size Model - 1/48 Scale Gundam',
        'type' => 'MEGA',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);
    $mgex = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120094',
        'sku' => 'CAT-MGEX-RECEIVED',
        'description' => 'MGEX 1/100 STRIKE FREEDOM GUNDAM',
        'type' => 'MGEX',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);
    $draftOnlyMg = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120095',
        'sku' => 'CAT-MG-UNRECEIVED',
        'description' => 'MG only on unreceived PO',
        'type' => 'MG',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);

    foreach ([[$receivedPo, $mega], [$receivedPo, $mgex], [$unreceivedPo, $draftOnlyMg]] as [$po, $product]) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $ordered = $service->orderedLatestArrivalProducts();

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $ordered))->toBe([
        'CAT-MEGA-RECEIVED',
        'CAT-MGEX-RECEIVED',
        'CAT-MG-UNRECEIVED',
    ]);
});

it('assigns a product on multiple received POs to the newest received PO for catalog order', function (): void {
    $service = new LatestArrivalCatalogOrderService(
        new LatestArrivalPushProductSortService(new ProductTypeDerivationService),
    );

    $olderPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120101',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-06-03',
    ]);
    $newerPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120102',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-06-11',
    ]);

    $mega = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120111',
        'sku' => 'CAT-MEGA-MULTI',
        'description' => 'Mega Size Model - 1/48 Scale Gundam',
        'type' => 'MEGA',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);
    $blockerMg = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120112',
        'sku' => 'CAT-BLOCK-MG',
        'description' => 'MG blocker on newer PO',
        'type' => 'MG',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);

    foreach ([[$olderPo, $mega], [$newerPo, $mega], [$newerPo, $blockerMg]] as [$po, $product]) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $ordered = $service->orderedLatestArrivalProducts();

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $ordered))->toBe([
        'CAT-MEGA-MULTI',
        'CAT-BLOCK-MG',
    ]);
});

it('prefers the newest received PO over an older received PO for the same product', function (): void {
    $service = new LatestArrivalCatalogOrderService(
        new LatestArrivalPushProductSortService(new ProductTypeDerivationService),
    );

    $olderPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120121',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-01-01',
    ]);
    $newerPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120122',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-06-03',
    ]);

    $mega = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120123',
        'sku' => 'CAT-MEGA-NEWEST-PO',
        'description' => 'Mega Size Model - 1/48 Scale Gundam',
        'type' => 'MEGA',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);

    foreach ([[$olderPo, $mega], [$newerPo, $mega]] as [$po, $product]) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $ordered = $service->orderedLatestArrivalProducts();

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $ordered))->toBe([
        'CAT-MEGA-NEWEST-PO',
    ]);
});

it('ignores POs flagged exclude_from_latest_arrivals_ordering when grouping and ranking', function (): void {
    $service = new LatestArrivalCatalogOrderService(
        new LatestArrivalPushProductSortService(new ProductTypeDerivationService),
    );

    $includedPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120301',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-07-15',
    ]);
    $excludedPo = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120302',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-07-16',
        'exclude_from_latest_arrivals_ordering' => true,
    ]);

    $sharedHg = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120311',
        'sku' => 'CAT-SHARED-HG',
        'description' => 'HG shared across POs',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);
    $includedMgex = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120312',
        'sku' => 'CAT-INCLUDED-MGEX',
        'description' => 'MGEX on included PO only',
        'type' => 'MGEX',
        'vendor' => 'Plamod',
        'latest_arrival' => true,
    ]);

    foreach ([[$includedPo, $sharedHg], [$includedPo, $includedMgex], [$excludedPo, $sharedHg]] as [$po, $product]) {
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $ordered = $service->orderedLatestArrivalProducts();

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $ordered))->toBe([
        'CAT-INCLUDED-MGEX',
        'CAT-SHARED-HG',
    ]);
});

it('sorts within a PO using the full grade order sequence', function (): void {
    $service = new LatestArrivalCatalogOrderService(
        new LatestArrivalPushProductSortService(new ProductTypeDerivationService),
    );

    $po = PurchaseOrder::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000120201',
        'vendor' => 'Plamod',
        'vendor_currency_code' => 'CAD',
        'received_date' => '2026-06-03',
    ]);

    $products = [
        ['sku' => 'CAT-POKEMON', 'type' => 'POKEMON', 'description' => 'Pokemon Model Kit'],
        ['sku' => 'CAT-30MS', 'type' => '30MS', 'description' => '30MS kit'],
        ['sku' => 'CAT-SD', 'type' => 'SD', 'description' => 'SD kit'],
        ['sku' => 'CAT-HG', 'type' => 'HG', 'description' => 'HG kit'],
        ['sku' => 'CAT-HGUC', 'type' => 'HGUC', 'description' => 'HGUC kit'],
        ['sku' => 'CAT-RG', 'type' => 'RG', 'description' => 'RG kit'],
        ['sku' => 'CAT-FM', 'type' => 'FM', 'description' => 'FULL MECHANICS kit'],
        ['sku' => 'CAT-RE', 'type' => 'RE', 'description' => 'RE 1/100 kit'],
        ['sku' => 'CAT-MG', 'type' => 'MG', 'description' => 'MG kit'],
        ['sku' => 'CAT-MGEX', 'type' => 'MGEX', 'description' => 'MGEX kit'],
        ['sku' => 'CAT-MEGA', 'type' => 'MEGA', 'description' => 'Mega Size Model kit'],
        ['sku' => 'CAT-PG', 'type' => 'PG', 'description' => 'PG kit'],
    ];

    foreach ($products as $row) {
        $product = Product::query()->create([
            'uuid' => '00000000-0000-0000-0000-'.substr(md5($row['sku']), 0, 12),
            'sku' => $row['sku'],
            'description' => $row['description'],
            'type' => $row['type'],
            'vendor' => 'Plamod',
            'latest_arrival' => true,
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'vendor' => 'Plamod',
            'qty_ordered' => 1,
        ]);
    }

    $ordered = $service->orderedLatestArrivalProducts();

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $ordered))->toBe([
        'CAT-PG',
        'CAT-MEGA',
        'CAT-MGEX',
        'CAT-MG',
        'CAT-RE',
        'CAT-FM',
        'CAT-RG',
        'CAT-HGUC',
        'CAT-HG',
        'CAT-SD',
        'CAT-30MS',
        'CAT-POKEMON',
    ]);
});
