<?php

declare(strict_types=1);

namespace App\Support\Plamod;

final class PlamodRestockCostCalculator
{
    /**
     * @return array{product: string, shipping: string, landed: string}|null
     */
    public static function newLandedBreakdown(?string $productCost, float $shippingPercent): ?array
    {
        $product = self::money2($productCost);
        if ($product === null) {
            return null;
        }

        $shipping = self::money2((string) round(((float) $product) * ($shippingPercent / 100), 2));
        $landed = self::money2((string) round(((float) $product) + (float) $shipping, 2));
        if ($shipping === null || $landed === null) {
            return null;
        }

        return [
            'product' => $product,
            'shipping' => $shipping,
            'landed' => $landed,
        ];
    }

    /**
     * @return array{product: string, shipping: string, landed: string}|null
     */
    public static function lastLandedBreakdown(?string $latestUnitCost, ?string $latestLandedUnitCost): ?array
    {
        $landed = self::money2($latestLandedUnitCost);
        if ($landed === null) {
            return null;
        }

        $product = self::money2($latestUnitCost);
        if ($product === null) {
            return [
                'product' => $landed,
                'shipping' => '0.00',
                'landed' => $landed,
            ];
        }

        $shipping = self::money2((string) max(0, round(((float) $landed) - ((float) $product), 2)));

        return [
            'product' => $product,
            'shipping' => $shipping ?? '0.00',
            'landed' => $landed,
        ];
    }

    public static function isProductCostDeltaAboveThreshold(
        ?string $lastProductCost,
        ?string $newProductCost,
        float $threshold = 0.03,
    ): bool {
        $last = self::money2($lastProductCost);
        $next = self::money2($newProductCost);
        if ($last === null || $next === null) {
            return false;
        }

        $lastValue = (float) $last;
        if ($lastValue <= 0) {
            return false;
        }

        return abs(((float) $next) - $lastValue) / $lastValue > $threshold;
    }

    public static function productCostDeltaPercent(?string $lastProductCost, ?string $newProductCost): ?float
    {
        $last = self::money2($lastProductCost);
        $next = self::money2($newProductCost);
        if ($last === null || $next === null) {
            return null;
        }

        $lastValue = (float) $last;
        if ($lastValue <= 0) {
            return null;
        }

        return round((((float) $next) - $lastValue) / $lastValue * 100, 1);
    }

    private static function money2(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return number_format((float) $trimmed, 2, '.', '');
    }
}
