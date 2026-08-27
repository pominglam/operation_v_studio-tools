<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;

final class ProductMerchandiseTaxonomyResolver
{
    /**
     * @return array{
     *     is_merchandise: bool,
     *     product_line: string|null,
     *     franchise: string|null,
     *     manufacturer: string|null
     * }
     */
    public function resolve(Product $product, string $searchableText): array
    {
        $empty = [
            'is_merchandise' => false,
            'product_line' => null,
            'franchise' => null,
            'manufacturer' => null,
        ];

        if (preg_match('/\b(?:KEYCHAIN|RUBBER MASCOT|MASCOT KEYCHAIN)\b/', $searchableText) !== 1) {
            return $empty;
        }

        $sku = mb_strtoupper(trim((string) $product->sku));

        return [
            'is_merchandise' => true,
            'product_line' => 'Keychains',
            'franchise' => str_contains($searchableText, 'GUNDAM') ? 'Gundam' : null,
            'manufacturer' => $this->manufacturer($sku, $searchableText),
        ];
    }

    private function manufacturer(string $sku, string $text): ?string
    {
        if (preg_match('/\b(?:50[0-9]{5}|507[0-9]{4})\b/', $sku) === 1
            || str_contains($text, 'GUNPLA')
        ) {
            return 'Bandai Spirits';
        }

        return null;
    }
}
