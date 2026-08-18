<?php

declare(strict_types=1);

namespace App\Services\Products;

/**
 * SQL subquery for qty on open PO lines (not yet received) used as "not arrived".
 */
final class ProductInboundOpenPoQtySql
{
    public static function expression(
        bool $includeDraftPurchaseOrders = true,
        string $productIdColumn = 'products.id',
        string $productSkuColumn = 'products.sku',
    ): string {
        $draftClause = $includeDraftPurchaseOrders
            ? ''
            : ' and (po.ordered_date is not null or po.shipped_date is not null)';

        return '(
            select coalesce(sum(
                case when coalesce(poi.qty_ordered, 0) > 0 then coalesce(poi.qty_ordered, 0) else 0 end
            ), 0)
            from purchase_order_items poi
            inner join purchase_orders po on po.id = poi.purchase_order_id
            where po.received_date is null'.$draftClause.'
              and (
                poi.product_id = '.$productIdColumn.'
                or (poi.product_id is null and poi.sku = '.$productSkuColumn.')
              )
        )';
    }
}
