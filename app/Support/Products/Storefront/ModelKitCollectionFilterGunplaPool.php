<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

/**
 * Only the gunpla hub uses the full gunpla smart collection for cross-grade client filters.
 * Mega-menu grade/series shelves paginate their own smart collections (16/page).
 */
final class ModelKitCollectionFilterGunplaPool
{
    /** @var list<string> Handles that use the full gunpla smart collection for cross-grade client filters. */
    private const GUNPLA_POOL_SOURCE_HANDLES = [
        'model-kits',
    ];

    /** @var list<string> Handles that reuse gunpla-hub filters but paginate their own smart collection. */
    private const FILTERED_SHELF_HANDLES = [
        'latest-arrivals',
    ];

    /**
     * @return list<string> Shopify collection handles
     */
    public static function handles(): array
    {
        return [
            ...self::GUNPLA_POOL_SOURCE_HANDLES,
            ...self::FILTERED_SHELF_HANDLES,
        ];
    }

    public static function usesGunplaProductPool(string $handle): bool
    {
        return in_array($handle, self::GUNPLA_POOL_SOURCE_HANDLES, true);
    }

    public static function handlesCsv(): string
    {
        return implode(',', self::handles());
    }
}
