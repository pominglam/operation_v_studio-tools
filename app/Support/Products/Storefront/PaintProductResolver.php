<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class PaintProductResolver
{
    /**
     * @return 'paint'|'surfacer'|'top-coat'|'panel-line'|'thinner'|'bundle'|null
     */
    public function resolveProduct(Product $product): ?string
    {
        if (! $this->belongsToPaintsDepartment($product)) {
            return null;
        }

        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));

        if (str_starts_with($sku, 'OVSP-')) {
            return 'bundle';
        }

        if (str_starts_with($sku, 'XPS-')) {
            return 'surfacer';
        }

        if ($this->isTopCoat($sku, $description)) {
            return 'top-coat';
        }

        if ($this->isThinner($sku, $description)) {
            return 'thinner';
        }

        if ($this->isPanelLine($sku, $description)) {
            return 'panel-line';
        }

        return 'paint';
    }

    /**
     * @return array<int, string>
     */
    public function resolveApplications(Product $product): array
    {
        if ($this->resolveProduct($product) !== 'paint') {
            return [];
        }

        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));
        $applications = [];

        if (preg_match('/^MC-(?:0[1-9]|1[0-8])$/', $sku) === 1 || preg_match('/^MMC-\d+$/', $sku) === 1) {
            return ['hand', 'airbrush'];
        }

        if (preg_match('/^(?:XG-|XSM-)/', $sku) === 1) {
            return ['pre-thinned-airbrush', 'airbrush'];
        }

        if (str_contains($description, 'hand paint') || str_contains($description, 'hand-paint')) {
            return ['hand'];
        }

        if (str_contains($description, 'airbrush') || str_contains($description, 'air brush')) {
            return ['airbrush'];
        }

        return ['airbrush'];
    }

    /**
     * @return 'solid'|'metallic'|'fluorescent'|null
     */
    public function resolvePaintType(Product $product): ?string
    {
        $productKind = $this->resolveProduct($product);
        if ($productKind !== 'paint' && $productKind !== 'panel-line') {
            return null;
        }

        $sku = strtoupper(trim((string) $product->sku));
        $description = strtolower(trim((string) $product->description));

        if (preg_match('/^(?:XSM-|MMC-)/', $sku) === 1 || str_contains($description, 'metallic')) {
            return 'metallic';
        }

        if (str_contains($description, 'fluorescent')) {
            return 'fluorescent';
        }

        if ($productKind !== 'paint') {
            return null;
        }

        return 'solid';
    }

    public function belongsToPaintsDepartment(Product $product): bool
    {
        $sku = strtoupper(trim((string) $product->sku));
        if ($this->isExcludedFromPaints($sku)) {
            return false;
        }

        $type = strtoupper(trim((string) ($product->type ?? '')));
        $description = strtolower(trim((string) $product->description));

        if ($type === 'BUNDLES' && str_starts_with($sku, 'OVSP-')) {
            return true;
        }

        if ($type === 'PANEL LINER' || $this->isPanelLine($sku, $description)) {
            return true;
        }

        if ($type === 'PAINT') {
            return true;
        }

        if (preg_match('/^(?:XG-|XPS-|XSM-|MC-|MMC-|OVSP-)/', $sku) === 1) {
            return true;
        }

        if (preg_match('/^T\d+$/', $sku) === 1 && str_contains($description, 'thinner')) {
            return true;
        }

        return false;
    }

    private function isExcludedFromPaints(string $sku): bool
    {
        if (str_starts_with($sku, 'E2E-')) {
            return true;
        }

        return in_array($sku, ['PT-AB', 'AB-D03', 'AB-D05', 'MS-B50', 'MS-58'], true)
            || $this->isPaintingToolPen($sku);
    }

    private function isPaintingToolPen(string $sku): bool
    {
        return in_array($sku, ['MP-01B', 'MP-01R', 'MP-02B', 'MP-02R'], true);
    }

    private function isTopCoat(string $sku, string $description): bool
    {
        if (in_array($sku, ['XG-901', 'XG-902', 'XG-903', 'MC-21'], true)) {
            return true;
        }

        return str_contains($description, 'topcoat')
            || str_contains($description, 'top coat')
            || str_contains($description, 'top-coat');
    }

    private function isThinner(string $sku, string $description): bool
    {
        if ($sku === 'MC-20') {
            return true;
        }

        return str_contains($description, 'thinner') || str_contains($description, 'levelling thinner');
    }

    private function isPanelLine(string $sku, string $description): bool
    {
        if ($this->isPaintingToolPen($sku)) {
            return false;
        }

        if (preg_match('/^MP-(?:1\d|2\d)/', $sku) === 1) {
            return true;
        }

        return str_contains($description, 'panel liner');
    }
}
