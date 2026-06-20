<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class AirbrushProductResolver
{
    public function belongsToAirbrushDepartment(Product $product): bool
    {
        $sku = strtoupper(trim((string) $product->sku));
        if ($this->isExcluded($sku)) {
            return false;
        }

        if (in_array($sku, ['PT-AB', 'AB-D03', 'AB-D05', 'MS-B50'], true)) {
            return true;
        }

        if (preg_match('/^GHAD-/', $sku) === 1) {
            return true;
        }

        return strtoupper(trim((string) ($product->type ?? ''))) === 'AIRBRUSH';
    }

    /**
     * @return 'tool'|'supply'|null
     */
    public function resolveRole(Product $product): ?string
    {
        if (! $this->belongsToAirbrushDepartment($product)) {
            return null;
        }

        $sku = strtoupper(trim((string) $product->sku));

        if ($sku === 'PT-AB' || preg_match('/^GHAD-/', $sku) === 1) {
            return 'tool';
        }

        return 'supply';
    }

    private function isExcluded(string $sku): bool
    {
        return str_starts_with($sku, 'E2E-');
    }
}
