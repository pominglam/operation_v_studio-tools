<?php

declare(strict_types=1);

namespace App\Support\SpecialOrders;

final class SpecialOrderPricingCaps
{
    public const DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD = '50.00';

    public const DEFAULT_OPV_MARGIN_CAP_CAD = '150.00';

    public const DEFAULT_SHIPPING_COST_AMOUNT = '100.00';

    public const DEFAULT_SHIPPING_COST_CURRENCY = SpecialOrderCurrency::CNY;

    public const DEFAULT_SHIPPING_COST_PER_KG_CNY = '29.00';

    /**
     * @return array{
     *   merchandiser_commission_cap_cad: string,
     *   opv_margin_cap_cad: string,
     *   default_shipping_cost_amount: string,
     *   default_shipping_cost_currency: string,
     *   default_shipping_cost_per_kg_cny: string
     * }
     */
    public static function defaults(): array
    {
        return [
            'merchandiser_commission_cap_cad' => self::DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD,
            'opv_margin_cap_cad' => self::DEFAULT_OPV_MARGIN_CAP_CAD,
            'default_shipping_cost_amount' => self::DEFAULT_SHIPPING_COST_AMOUNT,
            'default_shipping_cost_currency' => self::DEFAULT_SHIPPING_COST_CURRENCY,
            'default_shipping_cost_per_kg_cny' => self::DEFAULT_SHIPPING_COST_PER_KG_CNY,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   merchandiser_commission_cap_cad: string,
     *   opv_margin_cap_cad: string,
     *   default_shipping_cost_amount: string,
     *   default_shipping_cost_currency: string,
     *   default_shipping_cost_per_kg_cny: string
     * }
     */
    public static function normalize(array $payload): array
    {
        $defaults = self::defaults();

        $merchandiserCap = SpecialOrderCustomerPricing::normalizeMoney(
            $payload['merchandiser_commission_cap_cad'] ?? null,
        );
        $opvMarginCap = SpecialOrderCustomerPricing::normalizeMoney(
            $payload['opv_margin_cap_cad'] ?? null,
        );
        $shippingAmount = SpecialOrderCustomerPricing::normalizeMoney(
            $payload['default_shipping_cost_amount'] ?? $defaults['default_shipping_cost_amount'],
        );
        $shippingCurrency = SpecialOrderCurrency::normalize(
            (string) ($payload['default_shipping_cost_currency'] ?? $defaults['default_shipping_cost_currency']),
        );
        $shippingRatePerKg = SpecialOrderCustomerPricing::normalizeMoney(
            $payload['default_shipping_cost_per_kg_cny'] ?? $defaults['default_shipping_cost_per_kg_cny'],
        );

        if ($merchandiserCap === null || $opvMarginCap === null || $shippingAmount === null || $shippingCurrency === null || $shippingRatePerKg === null) {
            throw new \InvalidArgumentException('Pricing caps must be valid non-negative amounts with a supported shipping currency.');
        }

        return [
            'merchandiser_commission_cap_cad' => $merchandiserCap,
            'opv_margin_cap_cad' => $opvMarginCap,
            'default_shipping_cost_amount' => $shippingAmount,
            'default_shipping_cost_currency' => $shippingCurrency,
            'default_shipping_cost_per_kg_cny' => $shippingRatePerKg,
        ];
    }

    public static function applyCap(?string $amountCad, ?string $capCad): ?string
    {
        if ($amountCad === null || $amountCad === '') {
            return null;
        }

        $amount = (float) $amountCad;
        $cap = SpecialOrderCustomerPricing::normalizeMoney($capCad);
        if ($cap === null) {
            return number_format($amount, 2, '.', '');
        }

        return number_format(min($amount, (float) $cap), 2, '.', '');
    }

    public static function decodeStoredBody(?string $body): ?array
    {
        if (! is_string($body) || trim($body) === '') {
            return null;
        }

        $decoded = json_decode(trim($body), true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Stored pricing caps must be valid JSON.');
        }

        return self::normalize($decoded);
    }

    /**
     * @param  array{
     *   merchandiser_commission_cap_cad: string,
     *   opv_margin_cap_cad: string,
     *   default_shipping_cost_amount: string,
     *   default_shipping_cost_currency: string,
     *   default_shipping_cost_per_kg_cny: string
     * }  $caps
     */
    public static function encode(array $caps): string
    {
        $normalized = self::normalize($caps);

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }
}
