<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Products\LatestArrivalCatalogOrderService;
use App\Services\Products\LatestArrivalPushProductSortService;
use App\Services\Products\ProductTypeDerivationService;

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
