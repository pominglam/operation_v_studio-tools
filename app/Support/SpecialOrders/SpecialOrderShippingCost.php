<?php

declare(strict_types=1);

namespace App\Support\SpecialOrders;

final class SpecialOrderShippingCost
{
    public const MODE_AMOUNT = 'amount';

    public const MODE_WEIGHT = 'weight';

    /** @var list<string> */
    public const MODES = [
        self::MODE_AMOUNT,
        self::MODE_WEIGHT,
    ];

    public static function normalizeMode(?string $mode): string
    {
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_AMOUNT;
    }

    public static function normalizeWeightKg(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $numeric = (float) $value;
        if (! is_finite($numeric) || $numeric < 0) {
            return null;
        }

        return number_format($numeric, 3, '.', '');
    }

    public static function computeAmountFromWeight(?string $weightKg, ?string $ratePerKgCny): ?string
    {
        $weight = self::normalizeWeightKg($weightKg);
        $rate = SpecialOrderCustomerPricing::normalizeMoney($ratePerKgCny);
        if ($weight === null || $rate === null) {
            return null;
        }

        return number_format((float) $weight * (float) $rate, 2, '.', '');
    }

    /**
     * @return array{
     *   shipping_cost_input_mode: string,
     *   shipping_weight_kg: string|null,
     *   shipping_cost_amount: string|null,
     *   shipping_cost_currency: string|null
     * }
     */
    public static function resolveQuote(
        ?string $mode,
        mixed $weightKg,
        mixed $amount,
        ?string $currency,
        string $ratePerKgCny,
    ): array {
        $normalizedMode = self::normalizeMode($mode);
        $normalizedWeight = self::normalizeWeightKg($weightKg);
        $normalizedAmount = SpecialOrderCustomerPricing::normalizeMoney(
            is_string($amount) || is_numeric($amount) ? (string) $amount : null,
        );
        $normalizedCurrency = SpecialOrderCurrency::normalize((string) ($currency ?? SpecialOrderCurrency::CNY));

        if ($normalizedMode === self::MODE_WEIGHT) {
            $computedAmount = self::computeAmountFromWeight($normalizedWeight, $ratePerKgCny);

            return [
                'shipping_cost_input_mode' => self::MODE_WEIGHT,
                'shipping_weight_kg' => $normalizedWeight,
                'shipping_cost_amount' => $computedAmount ?? $normalizedAmount,
                'shipping_cost_currency' => SpecialOrderCurrency::CNY,
            ];
        }

        return [
            'shipping_cost_input_mode' => self::MODE_AMOUNT,
            'shipping_weight_kg' => null,
            'shipping_cost_amount' => $normalizedAmount,
            'shipping_cost_currency' => $normalizedCurrency,
        ];
    }

    /**
     * @return array{
     *   actual_shipping_cost_input_mode: string,
     *   actual_shipping_weight_kg: string|null,
     *   actual_shipping_cost_amount: string|null,
     *   actual_shipping_cost_currency: string|null
     * }
     */
    public static function resolveActual(
        ?string $mode,
        mixed $weightKg,
        mixed $amount,
        ?string $currency,
        string $ratePerKgCny,
    ): array {
        $quote = self::resolveQuote($mode, $weightKg, $amount, $currency, $ratePerKgCny);

        return [
            'actual_shipping_cost_input_mode' => $quote['shipping_cost_input_mode'],
            'actual_shipping_weight_kg' => $quote['shipping_weight_kg'],
            'actual_shipping_cost_amount' => $quote['shipping_cost_amount'],
            'actual_shipping_cost_currency' => $quote['shipping_cost_currency'],
        ];
    }
}
