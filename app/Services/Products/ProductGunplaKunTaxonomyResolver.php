<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;

final class ProductGunplaKunTaxonomyResolver
{
    /**
     * @return array{
     *     is_gunpla_kun: bool,
     *     manufacturer: string|null,
     *     franchise: string|null,
     *     scale: string|null
     * }
     */
    public function resolve(Product $product, string $searchableText): array
    {
        $empty = [
            'is_gunpla_kun' => false,
            'manufacturer' => null,
            'franchise' => null,
            'scale' => null,
        ];

        if ($this->isKeychainMerchandise($searchableText) || ! $this->isGunplaKun($product, $searchableText)) {
            return $empty;
        }

        return [
            'is_gunpla_kun' => true,
            'manufacturer' => 'Bandai Spirits',
            'franchise' => 'Gundam',
            'scale' => '1/1',
        ];
    }

    private function isKeychainMerchandise(string $searchableText): bool
    {
        return preg_match('/\b(?:KEYCHAIN|RUBBER MASCOT|MASCOT KEYCHAIN)\b/', $searchableText) === 1;
    }

    private function isGunplaKun(Product $product, string $searchableText): bool
    {
        $type = mb_strtoupper(trim((string) ($product->type ?? '')));
        if ($type === 'KUN DX') {
            return true;
        }

        return preg_match('/\b(?:GUNPLA|ZAKUPLA|CHARZAKU)-KUN(?:\s+DX)?\b/', $searchableText) === 1;
    }
}
