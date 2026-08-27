<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class SandingProductTypeResolver
{
    /**
     * @return 'sheet'|'stick'|'sponge'|'glass-file'|'board-plate'
     */
    public function resolve(Product $product): string
    {
        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));

        if (preg_match('/^MS-4[1-6]$/', $sku) === 1) {
            return 'glass-file';
        }

        if (in_array($sku, ['MS-D2', 'MS-D4', 'MS-D15', 'MS-D20'], true)) {
            return 'board-plate';
        }

        if (preg_match('/^MS-JD/', $sku) === 1) {
            return 'board-plate';
        }

        if ($this->isStickSku($sku)) {
            return 'stick';
        }

        if ($this->isSpongeSku($sku)) {
            return 'sponge';
        }

        if (preg_match('/^MS-B/', $sku) === 1) {
            return 'sheet';
        }

        if (str_contains($description, 'polishing file')) {
            return 'glass-file';
        }

        if (
            str_contains($description, 'sanding stick')
            || str_contains($description, 'kamiyasu-sanding stick')
        ) {
            return 'stick';
        }

        if (str_contains($description, 'sponge')) {
            return 'sponge';
        }

        if (str_contains($description, 'adhesive sandpaper')) {
            return 'sheet';
        }

        if (str_contains($description, 'sanding board') || str_contains($description, 'sanding plate')) {
            return 'board-plate';
        }

        return 'sheet';
    }

    private function isStickSku(string $sku): bool
    {
        return preg_match('/^MS-E/', $sku) === 1
            || preg_match('/^GH-KS3-/', $sku) === 1;
    }

    private function isSpongeSku(string $sku): bool
    {
        if (
            preg_match('/^MS-A/', $sku) === 1
            || preg_match('/^MS-AT/', $sku) === 1
            || preg_match('/^MS-C/', $sku) === 1
            || preg_match('/^MS-DT/', $sku) === 1
        ) {
            return true;
        }

        return $this->isSpongeGritSku($sku);
    }

    private function isSpongeGritSku(string $sku): bool
    {
        if (! preg_match('/^MS-D\d+$/', $sku)) {
            return false;
        }

        return ! in_array($sku, ['MS-D2', 'MS-D4', 'MS-D15', 'MS-D20'], true);
    }
}
