<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\Models\CustomAsiaOrder;
use App\Support\CustomOrders\CustomAsiaOrderCustomerPricing;

final class CustomAsiaOrderCustomerPricingService
{
    /** @var list<string> */
    private const PRICING_KEYS = [
        'merchandiser_price_multiplier',
        'merchandiser_price_cad',
        'merchandiser_commission_override_cad',
        'our_price_multiplier',
        'customer_price_cad',
        'our_commission_override_cad',
        'deposit_percent',
        'deposit_amount_override_cad',
    ];

    /** @var list<string> */
    private const MERCHANDISER_PRICING_KEYS = [
        'merchandiser_price_multiplier',
        'merchandiser_price_cad',
        'merchandiser_commission_override_cad',
    ];

    /** @var list<string> */
    private const CUSTOMER_OFFER_KEYS = [
        'our_price_multiplier',
        'customer_price_cad',
        'our_commission_override_cad',
        'deposit_percent',
        'deposit_amount_override_cad',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolveCustomerOfferAttributes(CustomAsiaOrder $order, array $input): array
    {
        return $this->resolveAttributesForKeys($order, $input, self::CUSTOMER_OFFER_KEYS);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolveUpdateAttributes(CustomAsiaOrder $order, array $input): array
    {
        if ($this->hasNoPricingInput($input)) {
            return [];
        }

        return $this->resolveAttributesForKeys($order, $input, self::PRICING_KEYS);
    }

    /** @param  array<string, mixed>  $input */
    public function hasCustomerOfferInput(array $input): bool
    {
        return $this->hasAnyKey($input, self::CUSTOMER_OFFER_KEYS);
    }

    /** @param  array<string, mixed>  $input */
    public function hasNoPricingInput(array $input): bool
    {
        return ! $this->hasAnyKey($input, self::PRICING_KEYS);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function resolveAttributesForKeys(CustomAsiaOrder $order, array $input, array $keys): array
    {
        if (! $this->hasAnyKey($input, $keys)) {
            return [];
        }

        $attributes = [];

        if (in_array('merchandiser_price_multiplier', $keys, true) && array_key_exists('merchandiser_price_multiplier', $input)) {
            $attributes['merchandiser_price_multiplier'] = $this->normalizeMultiplier($input['merchandiser_price_multiplier']);
        }

        if (in_array('our_price_multiplier', $keys, true) && array_key_exists('our_price_multiplier', $input)) {
            $attributes['our_price_multiplier'] = $this->normalizeMultiplier($input['our_price_multiplier']);
        }

        if (in_array('merchandiser_price_cad', $keys, true) && array_key_exists('merchandiser_price_cad', $input)) {
            $attributes['merchandiser_price_cad'] = $this->normalizeOptionalMoney(
                $input['merchandiser_price_cad'],
                'Merchandiser price must be a valid amount.',
            );
        }

        if (in_array('merchandiser_commission_override_cad', $keys, true) && array_key_exists('merchandiser_commission_override_cad', $input)) {
            $attributes['merchandiser_commission_override_cad'] = $this->normalizeOptionalMoney(
                $input['merchandiser_commission_override_cad'],
                'Merchandiser commission must be a valid amount.',
            );
        }

        if (in_array('customer_price_cad', $keys, true) && array_key_exists('customer_price_cad', $input)) {
            $attributes['customer_price_cad'] = $this->normalizeOptionalMoney(
                $input['customer_price_cad'],
                'Customer price must be a valid amount.',
            );
        }

        if (in_array('our_commission_override_cad', $keys, true) && array_key_exists('our_commission_override_cad', $input)) {
            $attributes['our_commission_override_cad'] = $this->normalizeOptionalMoney(
                $input['our_commission_override_cad'],
                'Our commission must be a valid amount.',
            );
        }

        if (in_array('deposit_percent', $keys, true) && array_key_exists('deposit_percent', $input)) {
            $attributes['deposit_percent'] = $this->normalizeOptionalPercent(
                $input['deposit_percent'],
                'Deposit percent must be between 0 and 100.',
            );
        }

        if (in_array('deposit_amount_override_cad', $keys, true) && array_key_exists('deposit_amount_override_cad', $input)) {
            $attributes['deposit_amount_override_cad'] = $this->normalizeOptionalMoney(
                $input['deposit_amount_override_cad'],
                'Deposit amount must be a valid amount.',
            );
        }

        return $this->applyDepositDerivedFields($order, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyDepositDerivedFields(CustomAsiaOrder $order, array $attributes): array
    {
        if (array_key_exists('deposit_amount_override_cad', $attributes) && $attributes['deposit_amount_override_cad'] !== null) {
            $customerPrice = is_string($attributes['customer_price_cad'] ?? null)
                ? $attributes['customer_price_cad']
                : (is_string($order->customer_price_cad) ? $order->customer_price_cad : null);
            $derivedPercent = CustomAsiaOrderCustomerPricing::depositPercentFromAmount(
                $customerPrice,
                is_string($attributes['deposit_amount_override_cad']) ? $attributes['deposit_amount_override_cad'] : null,
            );
            if ($derivedPercent !== null) {
                $attributes['deposit_percent'] = $derivedPercent;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     */
    private function hasAnyKey(array $input, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeMultiplier(mixed $value): string
    {
        $multiplier = CustomAsiaOrderCustomerPricing::normalizeMultiplier($value);
        if ($multiplier === null) {
            throw new \InvalidArgumentException('Price multiplier must be greater than zero.');
        }

        return $multiplier;
    }

    private function normalizeOptionalMoney(mixed $value, string $invalidMessage): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = CustomAsiaOrderCustomerPricing::normalizeMoney($value);
        if ($normalized === null) {
            throw new \InvalidArgumentException($invalidMessage);
        }

        return $normalized;
    }

    private function normalizeOptionalPercent(mixed $value, string $invalidMessage): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = CustomAsiaOrderCustomerPricing::normalizePercent($value);
        if ($normalized === null) {
            throw new \InvalidArgumentException($invalidMessage);
        }

        return $normalized;
    }
}
