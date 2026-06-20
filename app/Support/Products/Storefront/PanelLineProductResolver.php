<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class PanelLineProductResolver
{
    /**
     * Accent pens and wiper tools — not liquid panel-liner bottles (those stay on Paints).
     */
    public function belongsToPanelLinersDepartment(Product $product): bool
    {
        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));

        return $this->isWiperPen($sku, $description)
            || $this->isAccentPen($sku, $description);
    }

    /**
     * @return 'tool'|'paint'|null
     */
    public function resolveKind(Product $product): ?string
    {
        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));

        if ($this->isWiperPen($sku, $description)) {
            return 'tool';
        }

        if ($this->isAccentPen($sku, $description)) {
            return 'paint';
        }

        return null;
    }

    private function isWiperPen(string $sku, string $description): bool
    {
        if (preg_match('/^MP-0[23]/', $sku) === 1) {
            return true;
        }

        return str_contains($description, 'seepage line wiper')
            || str_contains($description, 'wiper pen');
    }

    private function isAccentPen(string $sku, string $description): bool
    {
        if (preg_match('/^MP-01/', $sku) === 1) {
            return true;
        }

        return str_contains($description, 'panel line accent')
            || str_contains($description, 'accent pen');
    }
}
