<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Services\Products\Hlj\HljPdpResolverService;

final class ProductPdpSearchTermsService
{
    public function __construct(
        private readonly HljPdpResolverService $hlj,
    ) {}

    /**
     * Generate ordered search terms for PDP resolution across sources.
     *
     * - barcode, sku first (high precision)
     * - then name variants (high recall)
     *
     * @return array<int, string>
     */
    public function termsForProduct(Product $product): array
    {
        $barcode = is_string($product->barcode ?? null) ? trim((string) $product->barcode) : '';
        $sku = is_string($product->sku ?? null) ? trim((string) $product->sku) : '';
        $name = is_string($product->description ?? null) ? trim((string) $product->description) : '';

        $terms = [];
        if ($barcode !== '') $terms[] = $barcode;
        if ($sku !== '') $terms[] = $sku;

        if ($name !== '') {
            $terms[] = $name;

            // HLJ-style variants (stripping common tokens, etc).
            foreach ($this->hlj->queryVariantsForName($name) as $q) {
                $terms[] = $q;
            }

            // Bandai-style normalization improves search recall for many sites.
            $norm = $this->bandaiStyleNormalizeName($name);
            if ($norm !== '' && $norm !== $name) {
                $terms[] = $norm;
            }
        }

        $terms = array_values(array_unique(array_filter(array_map('strval', $terms), static fn (string $v): bool => trim($v) !== '')));

        // Keep this bounded (avoid excessive search fan-out).
        return array_slice($terms, 0, 40);
    }

    private function bandaiStyleNormalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '';

        // Remove scale tokens like "1/100".
        $name = preg_replace('/\b\d+\s*\/\s*\d+\b/u', '', $name) ?? $name;

        // Remove model codes like "MBF-02VV" that can reduce recall.
        $name = preg_replace('/\b[A-Z]{2,}-\d+[A-Z0-9-]*\b/iu', '', $name) ?? $name;

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        return $name;
    }
}

