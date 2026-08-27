<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

final class CustomAsiaOrderCurrency
{
    public const CAD = 'CAD';

    public const CNY = 'CNY';

    public const HKD = 'HKD';

    public const JPY = 'JPY';

    /** @var list<string> */
    public const ALL = [
        self::CAD,
        self::CNY,
        self::HKD,
        self::JPY,
    ];

    public static function normalize(string $value): ?string
    {
        $value = strtoupper(trim($value));
        if ($value === 'RMB') {
            $value = self::CNY;
        }

        return in_array($value, self::ALL, true) ? $value : null;
    }

    public static function label(string $value): string
    {
        return match (self::normalize($value)) {
            self::CAD => 'CAD',
            self::CNY => 'RMB',
            self::HKD => 'HKD',
            self::JPY => 'JPY',
            default => $value,
        };
    }

    public static function frankfurterCode(string $value): string
    {
        return self::normalize($value) ?? self::CAD;
    }
}
