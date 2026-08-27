<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

use App\Support\Pricing\CharmPricingCalculator;

final class CustomAsiaOrderCustomerPricing
{
    public const DEFAULT_MERCHANDISER_MULTIPLIER = '1.1';

    public const DEFAULT_OUR_MULTIPLIER = '1.4';

    public const DEFAULT_DEPOSIT_PERCENT = '20.00';

    public static function formulaPrice(?string $landedCostCad, ?string $multiplier, string $defaultMultiplier): ?string
    {
        $landedCostCad = is_string($landedCostCad) ? trim($landedCostCad) : null;
        if ($landedCostCad === null || $landedCostCad === '') {
            return null;
        }

        $multiplier = self::normalizeMultiplier($multiplier) ?? $defaultMultiplier;

        return CharmPricingCalculator::applyHighMultiplierReduction(
            CharmPricingCalculator::sellingPriceX99FromCost($landedCostCad, $multiplier),
            $landedCostCad,
        );
    }

    public static function normalizeMultiplier(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $numeric = (float) $value;
        if ($numeric <= 0) {
            return null;
        }

        return number_format($numeric, 2, '.', '');
    }

    public static function normalizePercent(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $numeric = (float) $value;
        if ($numeric < 0 || $numeric > 100) {
            return null;
        }

        return number_format($numeric, 2, '.', '');
    }

    public static function normalizeMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $numeric = (float) $value;
        if ($numeric < 0) {
            return null;
        }

        return number_format($numeric, 2, '.', '');
    }

    public static function effectiveMultiplier(?string $landedCostCad, ?string $customerPriceCad): ?string
    {
        $landedCostCad = is_string($landedCostCad) ? trim($landedCostCad) : null;
        $customerPriceCad = is_string($customerPriceCad) ? trim($customerPriceCad) : null;
        if ($landedCostCad === null || $landedCostCad === '' || $customerPriceCad === null || $customerPriceCad === '') {
            return null;
        }

        $landed = (float) $landedCostCad;
        $price = (float) $customerPriceCad;
        if ($landed <= 0 || $price <= 0) {
            return null;
        }

        return number_format($price / $landed, 2, '.', '');
    }

    public static function commissionAboveLanded(?string $landedCostCad, ?string $priceCad): ?string
    {
        $landedCostCad = is_string($landedCostCad) ? trim($landedCostCad) : null;
        $priceCad = is_string($priceCad) ? trim($priceCad) : null;
        if ($landedCostCad === null || $landedCostCad === '' || $priceCad === null || $priceCad === '') {
            return null;
        }

        $landed = (float) $landedCostCad;
        $price = (float) $priceCad;
        if ($landed <= 0 || $price <= 0) {
            return null;
        }

        return number_format(max(0, $price - $landed), 2, '.', '');
    }

    public static function ourCommission(
        ?string $landedCostCad,
        ?string $merchandiserPriceCad,
        ?string $customerPriceCad,
        ?string $commissionOverrideCad = null,
        ?string $merchandiserMultiplier = null,
        ?string $ourMultiplier = null,
        ?string $merchandiserCommissionOverrideCad = null,
        ?string $opvMarginCapCad = null,
        ?string $merchandiserCommissionCapCad = null,
    ): ?string {
        $override = self::normalizeMoney($commissionOverrideCad);
        if ($override !== null) {
            return $override;
        }

        $landedCostCad = is_string($landedCostCad) ? trim($landedCostCad) : null;
        if ($landedCostCad === null || $landedCostCad === '') {
            return null;
        }

        $landed = (float) $landedCostCad;
        if ($landed <= 0) {
            return null;
        }

        $merchandiserMultiplier = self::normalizeMultiplier($merchandiserMultiplier);
        $ourMultiplier = self::normalizeMultiplier($ourMultiplier);
        if ($merchandiserMultiplier !== null && $ourMultiplier !== null && (float) $ourMultiplier >= (float) $merchandiserMultiplier) {
            $merchandiserCommission = self::merchandiserCommission(
                $landedCostCad,
                $merchandiserPriceCad,
                $merchandiserCommissionOverrideCad,
                $merchandiserMultiplier,
                $merchandiserCommissionCapCad,
            );
            $spread = $landed + (float) ($merchandiserCommission ?? '0');

            return CustomAsiaOrderPricingCaps::applyCap(
                number_format($spread * ((float) $ourMultiplier - (float) $merchandiserMultiplier), 2, '.', ''),
                $opvMarginCapCad,
            );
        }

        $merchandiserCommission = self::merchandiserCommission(
            $landedCostCad,
            $merchandiserPriceCad,
            $merchandiserCommissionOverrideCad,
            $merchandiserMultiplier,
            $merchandiserCommissionCapCad,
        );
        $customerPriceCad = is_string($customerPriceCad) ? trim($customerPriceCad) : null;
        if ($merchandiserCommission === null || $customerPriceCad === null || $customerPriceCad === '') {
            return null;
        }

        $customer = (float) $customerPriceCad;
        if ($customer <= 0) {
            return null;
        }

        $spread = $landed + (float) $merchandiserCommission;

        return CustomAsiaOrderPricingCaps::applyCap(
            number_format(max(0, $customer - $spread), 2, '.', ''),
            $opvMarginCapCad,
        );
    }

    public static function depositAmountFromPercent(?string $customerPriceCad, ?string $depositPercent): ?string
    {
        $customerPriceCad = is_string($customerPriceCad) ? trim($customerPriceCad) : null;
        $depositPercent = self::normalizePercent($depositPercent);
        if ($customerPriceCad === null || $customerPriceCad === '' || $depositPercent === null) {
            return null;
        }

        $price = (float) $customerPriceCad;
        if ($price <= 0) {
            return null;
        }

        return number_format(($price * (float) $depositPercent) / 100, 2, '.', '');
    }

    public static function depositPercentFromAmount(?string $customerPriceCad, ?string $depositAmountCad): ?string
    {
        $customerPriceCad = is_string($customerPriceCad) ? trim($customerPriceCad) : null;
        $depositAmountCad = self::normalizeMoney($depositAmountCad);
        if ($customerPriceCad === null || $customerPriceCad === '' || $depositAmountCad === null) {
            return null;
        }

        $price = (float) $customerPriceCad;
        if ($price <= 0) {
            return null;
        }

        $percent = ((float) $depositAmountCad / $price) * 100;
        if ($percent < 0 || $percent > 100) {
            return null;
        }

        return number_format($percent, 2, '.', '');
    }

    public static function depositAmount(
        ?string $customerPriceCad,
        ?string $depositPercent,
        ?string $depositAmountOverrideCad,
    ): ?string {
        $override = self::normalizeMoney($depositAmountOverrideCad);
        if ($override !== null) {
            return $override;
        }

        return self::depositAmountFromPercent($customerPriceCad, $depositPercent);
    }

    public static function balance(
        ?string $customerPriceCad,
        ?string $depositPercent,
        ?string $depositAmountOverrideCad = null,
    ): ?string {
        $customerPriceCad = is_string($customerPriceCad) ? trim($customerPriceCad) : null;
        if ($customerPriceCad === null || $customerPriceCad === '') {
            return null;
        }

        $depositAmount = self::depositAmount($customerPriceCad, $depositPercent, $depositAmountOverrideCad);
        if ($depositAmount === null) {
            return number_format((float) $customerPriceCad, 2, '.', '');
        }

        return number_format(max(0, (float) $customerPriceCad - (float) $depositAmount), 2, '.', '');
    }

    public static function merchandiserCommission(
        ?string $landedCostCad,
        ?string $merchandiserPriceCad,
        ?string $commissionOverrideCad,
        ?string $merchandiserMultiplier = null,
        ?string $commissionCapCad = null,
    ): ?string {
        $override = self::normalizeMoney($commissionOverrideCad);
        if ($override !== null) {
            return $override;
        }

        $landedCostCad = is_string($landedCostCad) ? trim($landedCostCad) : null;
        if ($landedCostCad === null || $landedCostCad === '') {
            return null;
        }

        $landed = (float) $landedCostCad;
        if ($landed <= 0) {
            return null;
        }

        $merchandiserMultiplier = self::normalizeMultiplier($merchandiserMultiplier);
        if ($merchandiserMultiplier !== null && (float) $merchandiserMultiplier >= 1) {
            return CustomAsiaOrderPricingCaps::applyCap(
                number_format($landed * ((float) $merchandiserMultiplier - 1), 2, '.', ''),
                $commissionCapCad,
            );
        }

        return CustomAsiaOrderPricingCaps::applyCap(
            self::commissionAboveLanded($landedCostCad, $merchandiserPriceCad),
            $commissionCapCad,
        );
    }
}
