<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final class CharmPricingCalculator
{
    private const float MIN_REDUCED_MULTIPLIER = 1.45;

    /** Step-1 formula price must exceed this multiple of cost before stepping down one X.99 tier. */
    private const float MIN_FORMULA_MULTIPLIER_FOR_REDUCTION = 1.55;

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

    /**
     * Pick the X.99 CAD price closest to cost × multiplier. Ties prefer the higher tier.
     */
    public static function nearestSellingPriceX99FromCost(?string $unitCost, string $multiplier): ?string
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
        $targetCents = intdiv($raw + intdiv($den, 2), $den);

        return self::centsToMoney(self::nearestX99Cents($targetCents));
    }

    /**
     * Snap an entered CAD price to the closest X.99 (ties go up). Same grid as catalog set-prices.
     */
    public static function nearestX99Price(?string $price): ?string
    {
        $price = $price !== null ? trim($price) : null;
        if ($price === null || $price === '') {
            return null;
        }

        $cents = self::moneyToCents($price);
        if ($cents <= 0) {
            return null;
        }

        return self::centsToMoney(self::nearestX99Cents($cents));
    }

    /**
     * Prefer one X.99 tier below the formula price when the formula price is over 1.55× cost
     * and the reduced price remains at least 1.45× cost.
     */
    public static function applyHighMultiplierReduction(?string $sellingPrice, ?string $unitCost): ?string
    {
        $sellingPrice = $sellingPrice !== null ? trim($sellingPrice) : null;
        $unitCost = $unitCost !== null ? trim($unitCost) : null;
        if ($sellingPrice === null || $sellingPrice === '') {
            return null;
        }

        if ($unitCost === null || $unitCost === '') {
            return $sellingPrice;
        }

        $costValue = (float) $unitCost;
        $priceValue = (float) $sellingPrice;
        if ($costValue <= 0 || $priceValue <= 0) {
            return $sellingPrice;
        }

        if (($priceValue / $costValue) <= self::MIN_FORMULA_MULTIPLIER_FOR_REDUCTION) {
            return $sellingPrice;
        }

        $reducedValue = $priceValue - 1.0;
        if ($reducedValue <= 0) {
            return $sellingPrice;
        }

        if (($reducedValue / $costValue) < self::MIN_REDUCED_MULTIPLIER) {
            return $sellingPrice;
        }

        return number_format($reducedValue, 2, '.', '');
    }

    private static function nearestX99Cents(int $targetCents): int
    {
        if ($targetCents <= 99) {
            return 99;
        }

        if ($targetCents % 100 === 99) {
            return $targetCents;
        }

        $dollars = intdiv($targetCents, 100);
        $upper = ($dollars * 100) + 99;
        $lower = (($dollars - 1) * 100) + 99;

        if (abs($targetCents - $lower) < abs($upper - $targetCents)) {
            return $lower;
        }

        return $upper;
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
