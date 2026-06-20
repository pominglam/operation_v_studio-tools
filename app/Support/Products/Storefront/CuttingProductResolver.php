<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class CuttingProductResolver
{
    /**
     * @return 'nipper'|'knife'|'blade'|null
     */
    public function resolveCategory(Product $product): ?string
    {
        $sku = strtoupper(trim((string) $product->sku));
        $type = strtoupper(trim((string) ($product->type ?? '')));
        $description = strtolower(trim((string) $product->description));

        if ($this->isExcludedSku($sku)) {
            return null;
        }

        if ($type === 'NIPPER' || preg_match('/^GH-(?:PN|SPN)-/', $sku) === 1) {
            return 'nipper';
        }

        if ($this->isKnifeSku($sku, $description)) {
            return 'knife';
        }

        if ($this->isBladeSku($sku, $description)) {
            return 'blade';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function resolveStyles(Product $product): array
    {
        $category = $this->resolveCategory($product);
        if ($category === null) {
            return [];
        }

        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));
        $styles = [];

        if ($category === 'nipper') {
            if ($this->isBeginnerNipper($sku, $description)) {
                $styles[] = 'beginner';
            }

            if ($this->isDoubleEdgeNipper($sku, $description)) {
                $styles[] = 'double-edge';
            }

            if ($this->isSingleEdgeNipper($sku, $description)) {
                $styles[] = 'single-edge';
            }
        }

        if ($category === 'knife') {
            if ($this->isCeramicKnife($sku, $description)) {
                $styles[] = 'ceramic';
            } elseif ($this->isUtilityOlfaKnife($sku, $description)) {
                $styles[] = 'utility-olfa';
            } else {
                $styles[] = 'pen-knife';
            }
        }

        return array_values(array_unique($styles));
    }

    private function isExcludedSku(string $sku): bool
    {
        if (str_starts_with($sku, 'E2E-')) {
            return true;
        }

        return in_array($sku, ['MS-23', 'MS-25', 'MS-25B', 'MS-27'], true);
    }

    private function isBladeSku(string $sku, string $description): bool
    {
        if (in_array($sku, ['MS-26', 'MS-28', 'MS-29'], true)) {
            return true;
        }

        return str_contains($description, 'replacement blade')
            || str_contains($description, '替换刀片')
            || str_contains($description, 'carbon steel blades');
    }

    private function isKnifeSku(string $sku, string $description): bool
    {
        if (in_array($sku, ['MS-26', 'MS-28', 'MS-29'], true)) {
            return false;
        }

        if (preg_match('/^AK-/', $sku) === 1) {
            return true;
        }

        if (in_array($sku, ['MS-21', 'MS-21B', 'MS-22', 'MS-24'], true)) {
            return true;
        }

        return str_contains($description, 'pen knife')
            || str_contains($description, '笔刀');
    }

    private function isBeginnerNipper(string $sku, string $description): bool
    {
        return $sku === 'MS-104'
            || $sku === 'MS-102'
            || str_contains($description, 'beginner')
            || str_contains($description, '入门');
    }

    private function isDoubleEdgeNipper(string $sku, string $description): bool
    {
        return in_array($sku, ['MS-100', 'MS-112'], true)
            || str_contains($description, 'double-edged')
            || str_contains($description, 'double edge')
            || str_contains($description, '双刃');
    }

    private function isSingleEdgeNipper(string $sku, string $description): bool
    {
        if ($this->isDoubleEdgeNipper($sku, $description)) {
            return false;
        }

        return preg_match('/^MS-10/', $sku) === 1
            || $sku === 'MS-103'
            || str_contains($description, 'single blade')
            || str_contains($description, '单刃');
    }

    private function isCeramicKnife(string $sku, string $description): bool
    {
        return $sku === 'MS-24'
            || str_contains($description, 'ceramic')
            || str_contains($description, '陶瓷');
    }

    private function isUtilityOlfaKnife(string $sku, string $description): bool
    {
        return preg_match('/^AK-/', $sku) === 1
            || str_contains($description, 'olfa');
    }
}
