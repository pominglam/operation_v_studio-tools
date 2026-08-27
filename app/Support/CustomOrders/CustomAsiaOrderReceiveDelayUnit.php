<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

final class CustomAsiaOrderReceiveDelayUnit
{
    public const DAYS = 'days';

    public const WEEKS = 'weeks';

    public const MONTHS = 'months';

    /** @var list<string> */
    public const ALL = [
        self::DAYS,
        self::WEEKS,
        self::MONTHS,
    ];

    public const DEFAULT_AMOUNT = 6;

    public const DEFAULT_UNIT = self::WEEKS;

    public static function defaultDays(): int
    {
        return self::toDays(self::DEFAULT_AMOUNT, self::DEFAULT_UNIT);
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, self::ALL, true) ? $value : null;
    }

    public static function label(string $value): string
    {
        return match (self::normalize($value)) {
            self::DAYS => 'Days',
            self::WEEKS => 'Weeks',
            self::MONTHS => 'Months',
            default => $value,
        };
    }

    public static function toDays(int $amount, string $unit): int
    {
        return match (self::normalize($unit)) {
            self::WEEKS => $amount * 7,
            self::MONTHS => $amount * 30,
            default => $amount,
        };
    }

    public static function formatLabel(?int $amount, ?string $unit): ?string
    {
        if ($amount === null || $amount <= 0 || $unit === null || self::normalize($unit) === null) {
            return null;
        }

        $unitLabel = match (self::normalize($unit)) {
            self::WEEKS => $amount === 1 ? 'week' : 'weeks',
            self::MONTHS => $amount === 1 ? 'month' : 'months',
            default => $amount === 1 ? 'day' : 'days',
        };

        return $amount.' '.$unitLabel;
    }
}
