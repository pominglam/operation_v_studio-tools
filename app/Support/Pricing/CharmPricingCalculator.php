<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final class CharmPricingCalculator
{
    /**
     * Compute a CAD selling price using charm pricing (.99) from unit cost × multiplier.
     * Example: cost 4.00 × 1.5 = 6.00 → 6.99.
     */
    public static function sellingPriceX99FromCost(?string $unitCost, string $multiplier): ?string
    {
        $unitCost = $unitCost !== null ? trim($unitCost) : null;
        if ($unitCost === null || $unitCost === '') {
            return null;
        }

        $cents = self::moneyToCents($unitCost);
        if ($cents <= 0) {
            return null;
        }

        [$num, $den] = self::decimalToFraction($multiplier);
        if ($num <= 0 || $den <= 0) {
            return null;
        }

        $raw = $cents * $num;
        $baseCents = intdiv($raw + $den - 1, $den);

        $dollars = intdiv($baseCents, 100);
        $sellingCents = ($dollars * 100) + 99;

        return self::centsToMoney($sellingCents);
    }

    private static function moneyToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '') {
            return 0;
        }

        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');

        if (! preg_match('/^\d+(\.\d{1,4})?$/', $amount)) {
            return 0;
        }

        [$whole, $frac] = array_pad(explode('.', $amount, 2), 2, '');
        $frac = str_pad(substr($frac, 0, 4), 4, '0');

        $cents = ((int) $whole * 100) + (int) substr($frac, 0, 2);
        $third = (int) ($frac[2] ?? 0);
        if ($third >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function decimalToFraction(string $multiplier): array
    {
        $multiplier = trim($multiplier);
        if ($multiplier === '' || ! preg_match('/^\d+(\.\d+)?$/', $multiplier)) {
            return [0, 1];
        }

        if (! str_contains($multiplier, '.')) {
            return [(int) $multiplier, 1];
        }

        [$whole, $frac] = explode('.', $multiplier, 2);
        $den = 10 ** strlen($frac);
        $num = ((int) $whole * $den) + (int) $frac;

        return [$num, $den];
    }

    private static function centsToMoney(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);
        $whole = intdiv($cents, 100);
        $frac = $cents % 100;
        $out = sprintf('%d.%02d', $whole, $frac);

        return $negative ? '-'.$out : $out;
    }
}
