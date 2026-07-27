<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Support;

use Illuminate\Support\Carbon;

final class ShopifyGraphQlNodeParser
{
    public static function legacyString(mixed $legacy): ?string
    {
        if ($legacy === null || $legacy === '') {
            return null;
        }
        if (is_int($legacy) || is_float($legacy)) {
            return (string) (int) $legacy;
        }
        if (is_string($legacy)) {
            return trim($legacy) !== '' ? $legacy : null;
        }

        return null;
    }

    public static function timestamp(?string $iso): ?Carbon
    {
        if (! is_string($iso) || trim($iso) === '') {
            return null;
        }

        try {
            return Carbon::parse($iso);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function timestampInShopTz(?string $iso): ?Carbon
    {
        $parsed = self::timestamp($iso);
        if ($parsed === null) {
            return null;
        }

        return $parsed->copy()->timezone('America/Toronto');
    }
}
