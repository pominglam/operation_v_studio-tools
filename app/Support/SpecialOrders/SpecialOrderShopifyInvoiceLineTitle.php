<?php

declare(strict_types=1);

namespace App\Support\SpecialOrders;

final class SpecialOrderShopifyInvoiceLineTitle
{
    private const string DEPOSIT_PREFIX = 'Deposit — ';

    private const string BALANCE_PREFIX = 'Balance — ';

    public static function forDeposit(string $baseTitle): string
    {
        return self::forKind('deposit', $baseTitle);
    }

    public static function forBalance(string $baseTitle): string
    {
        return self::forKind('balance', $baseTitle);
    }

    private static function forKind(string $kind, string $baseTitle): string
    {
        $baseTitle = self::stripKnownPrefixes(trim($baseTitle));
        if ($baseTitle === '') {
            throw new \InvalidArgumentException('Line title or product name is required.');
        }

        return match ($kind) {
            'deposit' => self::DEPOSIT_PREFIX.$baseTitle,
            'balance' => self::BALANCE_PREFIX.$baseTitle,
            default => throw new \InvalidArgumentException('Unknown invoice kind.'),
        };
    }

    private static function stripKnownPrefixes(string $title): string
    {
        foreach ([self::DEPOSIT_PREFIX, self::BALANCE_PREFIX] as $prefix) {
            if (str_starts_with($title, $prefix)) {
                return trim(substr($title, strlen($prefix)));
            }
        }

        return $title;
    }
}
