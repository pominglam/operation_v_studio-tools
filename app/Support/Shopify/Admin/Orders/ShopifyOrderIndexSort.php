<?php

declare(strict_types=1);

namespace App\Support\Shopify\Admin\Orders;

final class ShopifyOrderIndexSort
{
    public const string DEFAULT = 'ordered_at';

    /** @var list<string> */
    public const array ALLOWED = [
        'name',
        'contact',
        'ordered_at',
        'channel',
        'financial_status',
        'fulfillment_status',
        'subtotal',
        'line_count',
    ];

    public static function normalize(string $sortBy): string
    {
        $key = trim($sortBy);

        return in_array($key, self::ALLOWED, true) ? $key : self::DEFAULT;
    }

    public static function normalizeDir(string $dir): string
    {
        return strtolower(trim($dir)) === 'asc' ? 'asc' : 'desc';
    }
}
