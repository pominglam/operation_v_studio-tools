<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class DecalProductResolver
{
    public function belongsToDecalsDepartment(Product $product): bool
    {
        $sku = strtoupper(trim((string) $product->sku));

        return $this->isDecalSoftenerSku($sku) || $this->isWaterDecal($product);
    }

    public function isDecalSoftenerSku(string $sku): bool
    {
        return in_array(strtoupper(trim($sku)), ['ETC-03', 'ETC-04'], true);
    }

    public function isWaterDecal(Product $product): bool
    {
        if (strtolower(trim((string) $product->main_type)) === 'water decals') {
            return true;
        }

        return str_starts_with(strtoupper(trim((string) $product->sku)), 'WD-');
    }

    /**
     * @return 'softener'|'sheet'|null
     */
    public function resolveProductKind(Product $product): ?string
    {
        if (! $this->belongsToDecalsDepartment($product)) {
            return null;
        }

        if ($this->isWaterDecal($product)) {
            return 'sheet';
        }

        return 'softener';
    }

    /**
     * @return 'dspiae'|'g-rework'|'stedi'|'unclassified'|null
     */
    public function resolveBrand(Product $product): ?string
    {
        if (! $this->belongsToDecalsDepartment($product)) {
            return null;
        }

        $sku = strtoupper(trim((string) $product->sku));
        if ($this->isDecalSoftenerSku($sku)) {
            return 'dspiae';
        }

        $description = strtolower(trim((string) $product->description));
        $vendor = strtolower(trim((string) ($product->vendor ?? '')));

        if (str_contains($vendor, 'g-rework') || str_contains($vendor, 'grework')) {
            return 'g-rework';
        }

        if (str_contains($vendor, 'stedi')) {
            return 'stedi';
        }

        if (str_contains($description, 'g-rework') || str_contains($description, 'grework')) {
            return 'g-rework';
        }

        if ($this->isWaterDecal($product)) {
            if (str_contains($description, 'dspiae')) {
                return 'dspiae';
            }

            return 'unclassified';
        }

        if (str_contains($vendor, 'dspiae') || str_contains($description, 'dspiae')) {
            return 'dspiae';
        }

        return 'unclassified';
    }
}
