<?php

declare(strict_types=1);

namespace App\Support\PurchaseOrders;

final class PurchaseOrderAllocation
{
    public static function unitsFromTotals(int $sumReceived, int $sumOrdered, int $receivedEntriesCount): int
    {
        return $receivedEntriesCount > 0 ? $sumReceived : $sumOrdered;
    }

    public static function receivedQtySumSubquery(string $poAlias = 'po'): string
    {
        return "(select sum(coalesce(poi2.qty_received,0)) from purchase_order_items poi2 where poi2.purchase_order_id = {$poAlias}.id)";
    }

    public static function orderedQtySumSubquery(string $poAlias = 'po'): string
    {
        return "(select sum(coalesce(poi2.qty_ordered,0)) from purchase_order_items poi2 where poi2.purchase_order_id = {$poAlias}.id)";
    }

    public static function receivedQtyEnteredCountSubquery(string $poAlias = 'po'): string
    {
        return "(select count(*) from purchase_order_items poi2 where poi2.purchase_order_id = {$poAlias}.id and poi2.qty_received is not null)";
    }

    public static function allocationUnitsSubquery(string $poAlias = 'po'): string
    {
        $receivedSum = self::receivedQtySumSubquery($poAlias);
        $orderedSum = self::orderedQtySumSubquery($poAlias);
        $receivedCount = self::receivedQtyEnteredCountSubquery($poAlias);

        return "(
            case
                when {$receivedCount} > 0 then {$receivedSum}
                else {$orderedSum}
            end
        )";
    }

    public static function perUnitTotalSql(string $totalExpression, string $poAlias = 'po'): string
    {
        $units = self::allocationUnitsSubquery($poAlias);

        return "case
            when coalesce({$totalExpression},0) = 0 then null
            else coalesce({$totalExpression},0) / (nullif({$units},0) * 1.0)
        end";
    }
}
