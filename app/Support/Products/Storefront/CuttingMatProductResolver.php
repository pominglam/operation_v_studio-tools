<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class CuttingMatProductResolver
{
    /**
     * @var list<string>
     */
    private const EXCLUDED_SKUS = [
        'A3',
        'A4',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const COLOR_NEEDLES = [
        'purple' => ['grape purple', 'grape-purple', 'grape', 'purple'],
        'green' => ['matcha green', 'matcha-green', 'matcha', 'mint green', 'mint-green', 'mint', 'green'],
        'pink' => ['sakura pink', 'sakura-pink', 'sakura', 'pink'],
        'blue' => ['sky blue', 'sky-blue', 'blue'],
        'gray' => ['sky gray', 'sky-gray', 'sky grey', 'sky-grey', 'gray', 'grey'],
    ];

    public function belongsToCuttingMatsDepartment(Product $product): bool
    {
        $sku = strtoupper(trim((string) $product->sku));
        if (in_array($sku, self::EXCLUDED_SKUS, true) || str_starts_with($sku, 'E2E-')) {
            return false;
        }

        $description = strtolower(trim((string) $product->description));

        return str_contains($description, 'cutting mat');
    }

    /**
     * @return array{series: string, size: string, color: string}
     */
    public function filterAttributes(Product $product): array
    {
        $haystack = $this->haystack($product);

        return [
            'series' => $this->resolveSeries($haystack),
            'size' => $this->resolveSize($haystack),
            'color' => $this->resolveColor($haystack),
        ];
    }

    private function haystack(Product $product): string
    {
        return strtolower(trim((string) $product->sku).' '.trim((string) $product->description));
    }

    private function resolveSeries(string $haystack): string
    {
        if (str_contains($haystack, 'at-field') || str_contains($haystack, 'at field')) {
            return 'at-field';
        }

        if (
            str_contains($haystack, 'opv')
            || str_contains($haystack, 'op-v')
            || str_contains($haystack, 'operation v')
            || str_contains($haystack, 'operation-v')
        ) {
            return 'opv';
        }

        return 'no-series';
    }

    private function resolveSize(string $haystack): string
    {
        if (preg_match('/\ba4\b/', $haystack) === 1) {
            return 'a4';
        }

        if (preg_match('/\ba3\b/', $haystack) === 1) {
            return 'a3';
        }

        return '';
    }

    private function resolveColor(string $haystack): string
    {
        foreach (self::COLOR_NEEDLES as $slug => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $slug;
                }
            }
        }

        return '';
    }
}
