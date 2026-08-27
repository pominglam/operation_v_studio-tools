<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

final class CustomAsiaOrderCompetitorPriceSites
{
    public const SCOPE_FAST = 'fast';

    public const SCOPE_FULL = 'full';

    /** @var array<int, string> */
    public const FAST_SITE_KEYS = [
        'gundam_hangar',
        'hobby_sense',
        'argama_hobby',
        'canadian_gundam',
    ];

    /** @var array<int, string> */
    public const FULL_SITE_KEYS = [
        'gundam_hangar',
        'canadian_gundam',
        'hobby_sense',
        'argama_hobby',
        'panda_hobby',
        'hobby_bee',
        'hobby_wholesale',
        'meeplemart',
    ];

    public static function normalizeScope(?string $scope): string
    {
        $trimmed = is_string($scope) ? trim($scope) : '';

        return $trimmed === self::SCOPE_FULL ? self::SCOPE_FULL : self::SCOPE_FAST;
    }

    /** @return array<int, string> */
    public static function siteKeysForScope(string $scope): array
    {
        return self::normalizeScope($scope) === self::SCOPE_FULL
            ? self::FULL_SITE_KEYS
            : self::FAST_SITE_KEYS;
    }

    public static function siteLabel(string $siteKey): string
    {
        /** @var array<string, array{name: string}> $sites */
        $sites = config('price_research.sites', []);

        return $sites[$siteKey]['name'] ?? $siteKey;
    }

    public static function siteUrl(string $siteKey): ?string
    {
        /** @var array<string, array{base_url?: string}> $sites */
        $sites = config('price_research.sites', []);
        $url = $sites[$siteKey]['base_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    /**
     * @return array<int, array{site_key: string, site_name: string, site_url: string|null}>
     */
    public static function siteOptionsForScope(?string $scope): array
    {
        $options = [];
        foreach (self::siteKeysForScope(self::normalizeScope($scope)) as $siteKey) {
            $options[] = [
                'site_key' => $siteKey,
                'site_name' => self::siteLabel($siteKey),
                'site_url' => self::siteUrl($siteKey),
            ];
        }

        return $options;
    }

    /**
     * @param  array<int, string>  $siteKeys
     * @return array<int, array<string, mixed>>
     */
    public static function pendingQuotesForSiteKeys(array $siteKeys): array
    {
        $quotes = [];
        foreach ($siteKeys as $siteKey) {
            $quotes[] = [
                'site_key' => $siteKey,
                'site_name' => self::siteLabel($siteKey),
                'site_url' => self::siteUrl($siteKey),
                'status' => 'pending',
                'availability' => null,
                'currency' => 'CAD',
                'price' => null,
                'original_price' => null,
                'product_url' => null,
                'error_message' => null,
            ];
        }

        return $quotes;
    }
}
