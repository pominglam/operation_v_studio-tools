<?php

declare(strict_types=1);

namespace App\Support\SpecialOrders;

final class SpecialOrderLifecycleStatus
{
    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    public const ALL = 'all';

    public const CONSIDERING = 'considering';

    /** @var array<int, string> */
    public const ALL_VALUES = [
        self::ACTIVE,
        self::CONSIDERING,
        self::REJECTED,
        self::ALL,
    ];

    public static function normalize(?string $value): string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return in_array($trimmed, self::ALL_VALUES, true) ? $trimmed : self::ACTIVE;
    }
}
