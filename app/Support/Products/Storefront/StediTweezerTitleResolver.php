<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

/**
 * Canonical Shopify/ERP titles for Stedi tweezer SKUs (Option A naming).
 */
final class StediTweezerTitleResolver
{
    /** @var array<string, string> */
    private const array ULTRA_PRECISION_TITLES = [
        'MS-11' => 'Stedi Ultra-Precision Tweezers (Straight)',
        'MS-12' => 'Stedi Ultra-Precision Tweezers (Curved)',
        'MS-14' => 'Stedi Ultra-Precision Tweezers (Pointed)',
        'MS-15' => 'Stedi Ultra-Precision Tweezers (Curved Pointed)',
        'MS-16' => 'Stedi Ultra-Precision Tweezers (Curved Flat)',
        'MS-17' => 'Stedi Ultra-Precision Tweezers (Curved Arc)',
    ];

    /** @var array<string, string> */
    private const array THICK_WALL_TITLES = [
        'MS-160' => 'Stedi Thick-Wall Tweezers (Pointed)',
        'MS-161' => 'Stedi Thick-Wall Tweezers (Straight)',
        'MS-162' => 'Stedi Thick-Wall Tweezers (Flat)',
        'MS-163' => 'Stedi Thick-Wall Tweezers (Curved)',
    ];

    public function resolveTitle(Product $product): ?string
    {
        $sku = strtoupper(trim((string) $product->sku));

        return self::ULTRA_PRECISION_TITLES[$sku]
            ?? self::THICK_WALL_TITLES[$sku]
            ?? null;
    }

    /**
     * @return 'ultra-precision'|'thick-wall'|null
     */
    public function resolveLine(Product $product): ?string
    {
        $sku = strtoupper(trim((string) $product->sku));

        if (array_key_exists($sku, self::ULTRA_PRECISION_TITLES)) {
            return 'ultra-precision';
        }

        if (array_key_exists($sku, self::THICK_WALL_TITLES)) {
            return 'thick-wall';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function supportedSkus(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::ULTRA_PRECISION_TITLES),
            array_keys(self::THICK_WALL_TITLES),
        )));
    }
}
