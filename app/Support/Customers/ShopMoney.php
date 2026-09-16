<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class ShopMoney
{
    public static function add(string $left, string $right): string
    {
        return self::fromCents(self::toCents($left) + self::toCents($right));
    }

    public static function compare(string $left, string $right): int
    {
        return self::toCents($left) <=> self::toCents($right);
    }

    public static function divide(string $amount, int $divisor): string
    {
        if ($divisor <= 0) {
            return '0.00';
        }

        return self::fromCents((int) round(self::toCents($amount) / $divisor));
    }

    public static function toCents(string $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        $negative = str_starts_with($normalized, '-');
        $digits = preg_replace('/\D+/', '', $normalized) ?? '0';

        $cents = (int) $digits;

        return $negative ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
