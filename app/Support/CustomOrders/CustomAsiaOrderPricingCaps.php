<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

final class CustomAsiaOrderPricingCaps
{
    public const DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD = '50.00';

    public const DEFAULT_OPV_MARGIN_CAP_CAD = '150.00';

    /** @return array{merchandiser_commission_cap_cad: string, opv_margin_cap_cad: string} */
    public static function defaults(): array
    {
        return [
            'merchandiser_commission_cap_cad' => self::DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD,
            'opv_margin_cap_cad' => self::DEFAULT_OPV_MARGIN_CAP_CAD,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{merchandiser_commission_cap_cad: string, opv_margin_cap_cad: string}
     */
    public static function normalize(array $payload): array
    {
        $defaults = self::defaults();

        $merchandiserCap = CustomAsiaOrderCustomerPricing::normalizeMoney(
            $payload['merchandiser_commission_cap_cad'] ?? null,
        );
        $opvMarginCap = CustomAsiaOrderCustomerPricing::normalizeMoney(
            $payload['opv_margin_cap_cad'] ?? null,
        );

        if ($merchandiserCap === null || $opvMarginCap === null) {
            throw new \InvalidArgumentException('Pricing caps must be valid non-negative CAD amounts.');
        }

        return [
            'merchandiser_commission_cap_cad' => $merchandiserCap,
            'opv_margin_cap_cad' => $opvMarginCap,
        ];
    }

    public static function applyCap(?string $amountCad, ?string $capCad): ?string
    {
        if ($amountCad === null || $amountCad === '') {
            return null;
        }

        $amount = (float) $amountCad;
        $cap = CustomAsiaOrderCustomerPricing::normalizeMoney($capCad);
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

    /** @param array{merchandiser_commission_cap_cad: string, opv_margin_cap_cad: string} $caps */
    public static function encode(array $caps): string
    {
        $normalized = self::normalize($caps);

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }
}
