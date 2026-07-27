<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class MarkerProductResolver
{
    public function belongsToMarkersDepartment(Product $product): bool
    {
        $sku = strtoupper(trim((string) $product->sku));
        if ($this->isExcludedFromMarkers($sku)) {
            return false;
        }

        $type = strtoupper(trim((string) ($product->type ?? '')));

        return $type === 'MARKERS'
            || preg_match('/^(?:MK|MKF|MKM|DMM|MA)-/', $sku) === 1
            || preg_match('/^MS-(?:57|6[0-7]|7[0-7])$/', $sku) === 1;
    }

    /**
     * @return 'solid'|'metallic'|'fluorescent'|'clear'|null
     */
    public function resolveMarkerType(Product $product): ?string
    {
        if (! $this->belongsToMarkersDepartment($product)) {
            return null;
        }

        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));

        if (preg_match('/^MKF-/', $sku) === 1 || str_contains($description, 'fluorescent')) {
            return 'fluorescent';
        }

        if ($this->isMetallicMarker($sku, $description)) {
            return 'metallic';
        }

        if (preg_match('/\bclear\b/', $description) === 1) {
            return 'clear';
        }

        return 'solid';
    }

    /**
     * @return 'soft'|'hard'|null
     */
    public function resolveMarkerTip(Product $product): ?string
    {
        if (! $this->belongsToMarkersDepartment($product)) {
            return null;
        }

        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));

        if (str_contains($description, 'hard tip') || str_contains($description, 'hard-tip')) {
            return 'hard';
        }

        if (
            str_contains($description, 'soft tip')
            || str_contains($description, 'soft-tip')
            || str_contains($description, 'soft tipped')
        ) {
            return 'soft';
        }

        if (preg_match('/^(?:MK|MKF|MKM)-/', $sku) === 1) {
            return 'soft';
        }

        if (preg_match('/^(?:DMM|MA|MS)-/', $sku) === 1) {
            return 'hard';
        }

        return null;
    }

    /**
     * @return 'dspiae'|'stedi'|null
     */
    public function resolveMarkerBrand(Product $product): ?string
    {
        if (! $this->belongsToMarkersDepartment($product)) {
            return null;
        }

        $sku = strtoupper(trim((string) $product->sku));

        if (preg_match('/^(?:MK|MKF|MKM)-/', $sku) === 1) {
            return 'dspiae';
        }

        if (preg_match('/^(?:DMM|MA)-/', $sku) === 1) {
            return 'stedi';
        }

        if (preg_match('/^MS-(?:57|6[0-7]|7[0-7])$/', $sku) === 1) {
            return 'stedi';
        }

        $vendor = strtolower(trim((string) ($product->vendor ?? '')));
        if (str_contains($vendor, 'dspiae')) {
            return 'dspiae';
        }

        if (str_contains($vendor, 'stedi')) {
            return 'stedi';
        }

        return null;
    }

    private function isExcludedFromMarkers(string $sku): bool
    {
        if (str_starts_with($sku, 'E2E-')) {
            return true;
        }

        return $sku === 'MS-58';
    }

    private function isMetallicMarker(string $sku, string $description): bool
    {
        if (preg_match('/^(?:MKM-|MA-)/', $sku) === 1) {
            return true;
        }

        if (preg_match('/^DMM-(?:2\d|3[01])$/', $sku) === 1) {
            return true;
        }

        if (preg_match('/^MS-7[0-7]$/', $sku) === 1) {
            return true;
        }

        return str_contains($description, 'metallic') || str_contains($description, 'laser');
    }
}
