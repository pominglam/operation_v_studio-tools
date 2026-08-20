<?php

declare(strict_types=1);

namespace App\Services\Products;

/**
 * Canonical "not arrived" / inbound open PO qty semantics for operator-facing surfaces.
 *
 * Products grid default: include draft PO lines (matches ProductsController when
 * not_arrived_include_draft_orders is omitted).
 */
final class ProductNotArrivedQtyService
{
    public function productsGridIncludesDraftPurchaseOrders(): bool
    {
        return true;
    }

    public function sqlExpressionForProductsGrid(
        string $productIdColumn = 'products.id',
        string $productSkuColumn = 'products.sku',
    ): string {
        return $this->sqlExpression(
            $this->productsGridIncludesDraftPurchaseOrders(),
            $productIdColumn,
            $productSkuColumn,
        );
    }

    public function sqlExpression(
        bool $includeDraftPurchaseOrders,
        string $productIdColumn = 'products.id',
        string $productSkuColumn = 'products.sku',
    ): string {
        return ProductInboundOpenPoQtySql::expression(
            $includeDraftPurchaseOrders,
            $productIdColumn,
            $productSkuColumn,
        );
    }
}
