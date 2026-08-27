<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

final class CustomAsiaOrderLifecycleStatus
{
    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    public const ALL = 'all';

    /** @var array<int, string> */
    public const ALL_VALUES = [
        self::ACTIVE,
        self::REJECTED,
        self::ALL,
    ];

    public static function normalize(?string $value): string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return in_array($trimmed, self::ALL_VALUES, true) ? $trimmed : self::ACTIVE;
    }
}
