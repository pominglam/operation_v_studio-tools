<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;

final class ProductPreAssembledFigureResolver
{
    /**
     * @return array{
     *     is_figure: bool,
     *     manufacturer: string|null,
     *     franchise: string|null,
     *     product_line: string|null,
     *     scale: string|null
     * }
     */
    public function resolve(Product $product, string $searchableText): array
    {
        $empty = [
            'is_figure' => false,
            'manufacturer' => null,
            'franchise' => null,
            'product_line' => null,
            'scale' => null,
        ];

        $sku = mb_strtoupper(trim((string) $product->sku));
        if (! $this->isPreAssembledFigure($searchableText, $sku)) {
            return $empty;
        }

        return [
            'is_figure' => true,
            'manufacturer' => 'CCS Toys',
            'franchise' => $this->franchise($searchableText),
            'product_line' => 'CCS Toys',
            'scale' => $this->scale($searchableText),
        ];
    }

    private function isPreAssembledFigure(string $text, string $sku): bool
    {
        if (str_starts_with($sku, 'CCS')) {
            return true;
        }

        return preg_match('/\bCCS (?:TOYS|EVANGELION)\b/', $text) === 1;
    }

    private function franchise(string $text): ?string
    {
        return match (true) {
            str_contains($text, 'EVANGELION') => 'Evangelion',
            str_contains($text, 'GETTER') => 'Getter Robo',
            default => null,
        };
    }

    private function scale(string $text): ?string
    {
        if (preg_match('/\b1\/(6|7|8|9|10|12|144)\b/', $text, $match) === 1) {
            return '1/'.$match[1];
        }

        return 'non-scale';
    }
}
