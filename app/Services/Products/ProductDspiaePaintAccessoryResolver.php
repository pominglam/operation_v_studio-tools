<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;

final class ProductDspiaePaintAccessoryResolver
{
    /** @var array<int, string> */
    private const KNOWN_SKUS = [
        'MP-05',
    ];

    /**
     * @return array{
     *     is_paint_accessory: bool,
     *     label: string|null
     * }
     */
    public function resolve(Product $product, string $searchableText): array
    {
        $sku = mb_strtoupper(trim((string) $product->sku));
        if (! in_array($sku, self::KNOWN_SKUS, true)) {
            return ['is_paint_accessory' => false, 'label' => null];
        }

        return match ($sku) {
            'MP-05' => [
                'is_paint_accessory' => true,
                'label' => 'Infiltrating color mixing paper (50 sheets)',
            ],
            default => ['is_paint_accessory' => false, 'label' => null],
        };
    }
}
