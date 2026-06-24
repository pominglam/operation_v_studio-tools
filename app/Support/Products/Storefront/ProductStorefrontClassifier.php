<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;
use App\Services\Products\ProductExportService;

final class ProductStorefrontClassifier
{
    public function __construct(
        private readonly SandingProductTypeResolver $sandingProductTypeResolver,
        private readonly SandingGritResolver $sandingGritResolver,
        private readonly CuttingProductResolver $cuttingProductResolver,
        private readonly PaintProductResolver $paintProductResolver,
        private readonly PanelLineProductResolver $panelLineProductResolver,
        private readonly MarkerProductResolver $markerProductResolver,
        private readonly AirbrushProductResolver $airbrushProductResolver,
        private readonly ToolFamilyProductResolver $toolFamilyProductResolver,
    ) {}

    public function classify(Product $product): StorefrontClassification
    {
        $department = $this->resolveDepartment($product);
        $legacyTags = $this->legacyTags($product);
        $warnings = $this->warnings($product, $department, $legacyTags);

        $storefrontTags = $department !== null && $this->isDepartmentEnabled($department)
            ? $this->storefrontTagsForDepartment($product, $department)
            : [];

        $shopifyTags = $this->shopifyTagsForPush($product, $storefrontTags);

        return new StorefrontClassification(
            department: $department,
            legacyTags: $legacyTags,
            storefrontTags: $storefrontTags,
            shopifyTags: $shopifyTags,
            warnings: $warnings,
        );
    }

    private function resolveDepartment(Product $product): ?string
    {
        $sku = strtoupper(trim((string) $product->sku));

        if ($this->isMaskingTapeSku($sku)) {
            return StorefrontDepartment::TAPES;
        }

        if ($this->isScribingTapeSku($sku, $product)) {
            return StorefrontDepartment::TAPES;
        }

        if ($this->isDecalSoftenerSku($sku)) {
            return StorefrontDepartment::DECALS;
        }

        if ($this->isSandingProduct($product, $sku)) {
            return StorefrontDepartment::SANDING;
        }

        if ($this->cuttingProductResolver->resolveCategory($product) !== null) {
            return StorefrontDepartment::CUTTING;
        }

        if ($this->panelLineProductResolver->belongsToPanelLinersDepartment($product)) {
            return StorefrontDepartment::PANEL_LINERS;
        }

        if ($this->paintProductResolver->belongsToPaintsDepartment($product)) {
            return StorefrontDepartment::PAINTS;
        }

        if ($this->markerProductResolver->belongsToMarkersDepartment($product)) {
            return StorefrontDepartment::MARKERS;
        }

        if ($this->airbrushProductResolver->belongsToAirbrushDepartment($product)) {
            return StorefrontDepartment::AIRBRUSH;
        }

        $toolFamily = $this->toolFamilyProductResolver->resolveDepartment($product);
        if ($toolFamily !== null) {
            return $toolFamily;
        }

        return null;
    }

    private function isSandingProduct(Product $product, string $sku): bool
    {
        if ($sku === 'MS-B400' || $sku === 'MS-40') {
            return true;
        }

        if (strtoupper(trim((string) $product->type)) === 'SANDING') {
            return true;
        }

        if (preg_match('/^MS-4[1-6]$/', $sku) === 1) {
            return true;
        }

        if (in_array($sku, ['MS-D2', 'MS-D4', 'MS-D15', 'MS-D20', 'MS-E2'], true)) {
            return true;
        }

        if (preg_match('/^MS-JD/', $sku) === 1) {
            return true;
        }

        return preg_match('/^GH-KS3-/', $sku) === 1;
    }

    private function isMaskingTapeSku(string $sku): bool
    {
        return (bool) preg_match('/^MT-\d+$/', $sku);
    }

    private function isScribingTapeSku(string $sku, Product $product): bool
    {
        if (! preg_match('/^MS-\d{2}$/', $sku)) {
            return false;
        }

        return stripos((string) $product->description, 'tape') !== false;
    }

    private function isDecalSoftenerSku(string $sku): bool
    {
        return in_array($sku, ['ETC-03', 'ETC-04'], true);
    }

    /**
     * @return array<int, string>
     */
    private function storefrontTagsForDepartment(Product $product, string $department): array
    {
        return match ($department) {
            StorefrontDepartment::TAPES => $this->tapeTags($product),
            StorefrontDepartment::DECALS => $this->decalTags(),
            StorefrontDepartment::SANDING => $this->sandingTags($product),
            StorefrontDepartment::CUTTING => $this->cuttingTags($product),
            StorefrontDepartment::PAINTS => $this->paintTags($product),
            StorefrontDepartment::PANEL_LINERS => $this->panelLinerTags($product),
            StorefrontDepartment::MARKERS => $this->markerTags($product),
            StorefrontDepartment::AIRBRUSH => $this->airbrushTags($product),
            StorefrontDepartment::BRUSHES,
            StorefrontDepartment::DRILLS,
            StorefrontDepartment::TWEEZERS,
            StorefrontDepartment::SCRIBING,
            StorefrontDepartment::ADHESIVES,
            StorefrontDepartment::WORKSHOP_MISC => $this->toolFamilyTags($product, $department),
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function tapeTags(Product $product): array
    {
        $sku = strtoupper(trim((string) $product->sku));

        $typeTag = $this->isMaskingTapeSku($sku)
            ? StorefrontTag::TAPE_MASKING
            : StorefrontTag::TAPE_SCRIBING;

        $tags = [
            StorefrontTag::DEPT_TAPES,
            $typeTag,
        ];

        if (preg_match('/^(?:MT|MS)-(\d+)$/', $sku, $matches) === 1) {
            $width = (int) $matches[1];
            if ($width > 0) {
                $tags[] = StorefrontTag::tapeWidth($width);
            }
        }

        return $this->mergeTags([], $tags);
    }

    /**
     * @return array<int, string>
     */
    private function decalTags(): array
    {
        return [
            StorefrontTag::DEPT_DECALS,
            StorefrontTag::DECAL_SOFTENER,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sandingTags(Product $product): array
    {
        $productType = $this->sandingProductTypeResolver->resolve($product);
        $tags = [
            StorefrontTag::DEPT_SANDING,
            StorefrontTag::sandType($productType),
        ];

        $text = trim((string) $product->description).' '.trim((string) $product->sku);
        foreach ($this->sandingGritResolver->bucketsFromText($text) as $bucket) {
            $tags[] = StorefrontTag::sandGrit($bucket);
        }

        return $this->mergeTags([], $tags);
    }

    /**
     * @return array<int, string>
     */
    private function cuttingTags(Product $product): array
    {
        $category = $this->cuttingProductResolver->resolveCategory($product);
        if ($category === null) {
            return [];
        }

        $tags = [
            StorefrontTag::DEPT_CUTTING,
            StorefrontTag::cutCategory($category),
        ];

        $styles = $this->cuttingProductResolver->resolveStyles($product);

        foreach ($styles as $style) {
            $tags[] = StorefrontTag::cutStyle($style);
        }

        if (in_array('beginner', $styles, true)) {
            $tags[] = 'ts:cut:nipper-beginner';
        }

        return $this->mergeTags([], $tags);
    }

    /**
     * @return array<int, string>
     */
    private function paintTags(Product $product): array
    {
        $productKind = $this->paintProductResolver->resolveProduct($product);
        if ($productKind === null) {
            return [];
        }

        $tags = [
            StorefrontTag::DEPT_PAINTS,
            StorefrontTag::paintProduct($productKind),
        ];

        $applications = $this->paintProductResolver->resolveApplications($product);
        foreach ($applications as $application) {
            $tags[] = StorefrontTag::paintApplication($application);
        }

        $paintType = $this->paintProductResolver->resolvePaintType($product);
        if ($paintType !== null) {
            $tags[] = StorefrontTag::paintType($paintType);
        }

        if ($productKind === 'panel-line') {
            $tags[] = StorefrontTag::panelLinerKind('paint');
        }

        return $this->mergeTags([], $tags);
    }

    /**
     * @return array<int, string>
     */
    private function panelLinerTags(Product $product): array
    {
        $kind = $this->panelLineProductResolver->resolveKind($product);
        if ($kind === null) {
            return [];
        }

        return $this->mergeTags([], [
            StorefrontTag::DEPT_PANEL_LINERS,
            StorefrontTag::panelLinerKind($kind),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function markerTags(Product $product): array
    {
        $tags = [StorefrontTag::DEPT_MARKERS];

        $markerType = $this->markerProductResolver->resolveMarkerType($product);
        if ($markerType !== null) {
            $tags[] = StorefrontTag::markerType($markerType);
        }

        $markerTip = $this->markerProductResolver->resolveMarkerTip($product);
        if ($markerTip !== null) {
            $tags[] = StorefrontTag::markerTip($markerTip);
        }

        return $this->mergeTags([], $tags);
    }

    /**
     * @return array<int, string>
     */
    private function airbrushTags(Product $product): array
    {
        $tags = [StorefrontTag::DEPT_AIRBRUSH];

        $role = $this->airbrushProductResolver->resolveRole($product);
        if ($role !== null) {
            $tags[] = StorefrontTag::airbrushRole($role);
        }

        return $this->mergeTags([], $tags);
    }

    /**
     * @return array<int, string>
     */
    private function toolFamilyTags(Product $product, string $department): array
    {
        $deptTag = StorefrontTag::deptTagForDepartment($department);
        if ($deptTag === null) {
            return [];
        }

        $tags = [$deptTag];

        if ($department === StorefrontDepartment::BRUSHES) {
            $brushType = $this->toolFamilyProductResolver->resolveBrushType($product);
            if ($brushType !== null) {
                $tags[] = StorefrontTag::brushType($brushType);
            }
        }

        if ($department === StorefrontDepartment::DRILLS) {
            $drillType = $this->toolFamilyProductResolver->resolveDrillType($product);
            if ($drillType !== null) {
                $tags[] = StorefrontTag::drillType($drillType);
            }
        }

        if ($department === StorefrontDepartment::TWEEZERS) {
            $line = $this->toolFamilyProductResolver->resolveTweezerLine($product);
            if ($line !== null) {
                $tags[] = StorefrontTag::tweezerLine($line);
            }

            $style = $this->toolFamilyProductResolver->resolveTweezerStyle($product);
            if ($style !== null) {
                $tags[] = StorefrontTag::tweezerStyle($style);
            }
        }

        if ($department === StorefrontDepartment::SCRIBING) {
            $scribingType = $this->toolFamilyProductResolver->resolveScribingType($product);
            if ($scribingType !== null) {
                $tags[] = StorefrontTag::scribingType($scribingType);
            }
        }

        if ($department === StorefrontDepartment::ADHESIVES) {
            $adhesiveType = $this->toolFamilyProductResolver->resolveAdhesiveType($product);
            if ($adhesiveType !== null) {
                $tags[] = StorefrontTag::adhesiveType($adhesiveType);
            }
        }

        return $this->mergeTags([], $tags);
    }

    /**
     * @return array<int, string>
     */
    /**
     * Shopify push tags: ts:* storefront tags only (never legacy main_type/type).
     *
     * @param  array<int, string>  $storefrontTags
     * @return array<int, string>
     */
    private function shopifyTagsForPush(Product $product, array $storefrontTags): array
    {
        $tags = $this->mergeTags([], $storefrontTags);

        if ($product->latest_arrival) {
            $tags = $this->mergeTags($tags, [ProductExportService::LATEST_ARRIVAL_TAG]);
        }

        return $tags;
    }

    private function legacyTags(Product $product): array
    {
        $mainType = trim((string) $product->main_type);
        if ($mainType === '') {
            return [];
        }

        $tags = [$mainType];

        $type = trim((string) ($product->type ?? ''));
        if ($type !== '') {
            $tags[] = $type;
        }

        if ($product->latest_arrival) {
            $tags[] = ProductExportService::LATEST_ARRIVAL_TAG;
        }

        return $this->mergeTags([], $tags);
    }

    /**
     * @return array<int, string>
     */
    private function warnings(Product $product, ?string $department, array $legacyTags): array
    {
        $warnings = [];

        if ($department !== null && trim((string) $product->main_type) === '') {
            $warnings[] = 'empty_main_type';
        }

        if ($department !== null && ! $this->isDepartmentEnabled($department)) {
            $warnings[] = 'department_not_enabled';
        }

        return $warnings;
    }

    private function isDepartmentEnabled(string $department): bool
    {
        /** @var array<int, string> $enabled */
        $enabled = config('storefront_classification.enabled_departments', []);

        return in_array($department, $enabled, true);
    }

    /**
     * @param  array<int, string>  $base
     * @param  array<int, string>  $additional
     * @return array<int, string>
     */
    private function mergeTags(array $base, array $additional): array
    {
        $out = [];
        $seen = [];

        foreach ([...$base, ...$additional] as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }

            $key = strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $tag;
        }

        return $out;
    }
}
