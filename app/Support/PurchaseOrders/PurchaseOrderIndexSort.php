<?php

declare(strict_types=1);

namespace App\Support\PurchaseOrders;

final class PurchaseOrderIndexSort
{
    public const DEFAULT = 'ordered';

    /**
     * Public history-table keys plus internal `filter` (product/price-research picker).
     *
     * @var list<string>
     */
    public const KEYS = [
        'id',
        'status',
        'shipment',
        'created',
        'ordered',
        'estimated_arrival',
        'received',
        'on_shelves',
        'vendor',
        'items',
        'product_total',
        'shipping_total',
        'surcharge_total',
        'total',
        'filter',
    ];

    public static function normalize(string $sortBy): string
    {
        $sortBy = strtolower(trim($sortBy));

        return in_array($sortBy, self::KEYS, true) ? $sortBy : self::DEFAULT;
    }

    public static function normalizeDir(string $sortDir): string
    {
        return strtolower(trim($sortDir)) === 'asc' ? 'asc' : 'desc';
    }
}
