<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

final class CustomAsiaOrderIndexSort
{
    public const DEFAULT = 'created';

    /** @var list<string> */
    public const KEYS = [
        'created',
        'updated',
        'contact',
        'product_name',
        'media',
        'landed',
        'receive_delay',
        'product_cost',
        'shipping_cost',
        'customer_price',
        'deposit',
        'eta',
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
