<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class CustomerRetentionIndexSort
{
    public const DEFAULT = 'last_order_at';

    /**
     * @var list<string>
     */
    public const ALLOWED = [
        'display_name',
        'frequency_label',
        'rfm_group',
        'order_count',
        'spend',
        'aov',
        'cadence_status',
        'churn_status',
        'last_order_at',
        'recency_score',
        'frequency_score',
        'monetary_score',
    ];

    public static function normalize(string $sortBy): string
    {
        return in_array($sortBy, self::ALLOWED, true) ? $sortBy : self::DEFAULT;
    }

    public static function normalizeDir(string $dir): string
    {
        return $dir === 'asc' ? 'asc' : 'desc';
    }
}
