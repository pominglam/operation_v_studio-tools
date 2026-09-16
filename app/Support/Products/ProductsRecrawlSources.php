<?php

declare(strict_types=1);

namespace App\Support\Products;

final class ProductsRecrawlSources
{
    /** @var array<int, string> */
    public const IMAGE_SOURCES = [
        'bandai',
        'hlj',
        'gundamplanet',
        'newtype',
        'gundamhangar',
        'argama',
        'cool_dragon',
        'plamod',
    ];

    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        return array_values(array_unique([
            ...self::IMAGE_SOURCES,
            'competitor_price_research',
            ...self::configuredPriceSiteKeys(),
        ]));
    }

    /**
     * @param  array<int, string>  $sources
     * @return array<int, string>
     */
    public static function priceSiteKeysFrom(array $sources): array
    {
        return array_values(array_intersect($sources, self::configuredPriceSiteKeys()));
    }

    /**
     * @return array<int, string>
     */
    public static function configuredPriceSiteKeys(): array
    {
        /** @var array<string, mixed> $sites */
        $sites = config('price_research.sites', []);

        return array_values(array_filter(array_map('strval', array_keys($sites)), static fn (string $key): bool => $key !== ''));
    }
}
