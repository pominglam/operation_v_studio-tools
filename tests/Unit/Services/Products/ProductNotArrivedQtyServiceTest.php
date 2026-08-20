<?php

declare(strict_types=1);

use App\Services\Products\ProductInboundOpenPoQtySql;
use App\Services\Products\ProductNotArrivedQtyService;

it('uses the products grid draft PO default for grid expression', function (): void {
    $service = app(ProductNotArrivedQtyService::class);

    expect($service->productsGridIncludesDraftPurchaseOrders())->toBeTrue()
        ->and($service->sqlExpressionForProductsGrid())->toBe(
            ProductInboundOpenPoQtySql::expression(true),
        );
});
