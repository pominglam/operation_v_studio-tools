<?php

declare(strict_types=1);

namespace App\Support\Pricing;

/**
 * Catalog selling-price formula used by PO set-prices and store preorders:
 * cost × multiplier, then the closest X.99 (ties go up).
 */
final class OpvStandardCatalogPrice
{
    public static function fromCost(?string $unitCost, string $multiplier): ?string
    {
        return CharmPricingCalculator::nearestSellingPriceX99FromCost($unitCost, $multiplier);
    }

    public static function fromEnteredPrice(?string $price): ?string
    {
        return CharmPricingCalculator::nearestX99Price($price);
    }
}
