<?php

declare(strict_types=1);

namespace App\Support\StorePreorders;

final class OpvCatalogPricingSettings
{
    public const string DEFAULT_PRICE_MULTIPLIER = '1.50';

    public const string DEFAULT_DEPOSIT_PERCENT = '20.00';

    /**
     * @return array{price_multiplier: string, default_deposit_percent: string}
     */
    public static function defaults(): array
    {
        return [
            'price_multiplier' => self::DEFAULT_PRICE_MULTIPLIER,
            'default_deposit_percent' => self::DEFAULT_DEPOSIT_PERCENT,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{price_multiplier: string, default_deposit_percent: string}
     */
    public static function normalize(array $payload): array
    {
        $multiplier = self::normalizeMultiplier($payload['price_multiplier'] ?? null);
        $deposit = self::normalizeDeposit($payload['default_deposit_percent'] ?? null);
        if ($multiplier === null || $deposit === null) {
            throw new \InvalidArgumentException(
                'OPV catalog margin must be a multiplier between 1 and 5, and deposit between 1 and 100.',
            );
        }

        return [
            'price_multiplier' => $multiplier,
            'default_deposit_percent' => $deposit,
        ];
    }

    public static function encode(array $settings): string
    {
        return json_encode(self::normalize($settings), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{price_multiplier: string, default_deposit_percent: string}|null
     */
    public static function decodeStoredBody(string $body): ?array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return null;
        }

        return self::normalize($decoded);
    }

    public static function normalizeMultiplier(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $amount = (float) $value;
        if ($amount < 1 || $amount > 5) {
            return null;
        }

        return number_format($amount, 2, '.', '');
    }

    public static function normalizeDeposit(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $amount = (float) $value;
        if ($amount < 1 || $amount > 100) {
            return null;
        }

        return number_format($amount, 2, '.', '');
    }

    public static function normalizeMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $amount = (float) $value;
        if ($amount < 0.01 || $amount > 99999.99) {
            return null;
        }

        return number_format($amount, 2, '.', '');
    }
}
