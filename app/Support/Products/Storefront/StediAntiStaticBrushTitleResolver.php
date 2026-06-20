<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

/**
 * Canonical ERP/Shopify titles for Stedi anti-static brush SKUs.
 */
final class StediAntiStaticBrushTitleResolver
{
    /** @var array<string, string> */
    private const array TITLES = [
        'MS-81' => 'Stedi Anti-Static Brush (Soft, Large Head)',
        'MS-82' => 'Stedi Anti-Static Brush (Soft, Small Head)',
        'MS-83' => 'Stedi Anti-Static Brush (Bristle, Large Head)',
    ];

    public function resolveTitle(Product $product): ?string
    {
        $sku = strtoupper(trim((string) $product->sku));

        return self::TITLES[$sku] ?? null;
    }

    /**
     * @return list<string>
     */
    public function supportedSkus(): array
    {
        return array_keys(self::TITLES);
    }
}
