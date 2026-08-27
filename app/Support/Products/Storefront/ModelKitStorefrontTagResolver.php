<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class ModelKitStorefrontTagResolver
{
    /**
     * Absolute model-kit taxonomy tags from ERP canonical columns (not title search).
     *
     * @return array<int, string>
     */
    public function tagsForProduct(Product $product): array
    {
        if (mb_strtolower(trim((string) $product->main_type)) !== 'model kit') {
            return [];
        }

        $tags = [StorefrontTag::MK_DEPT_MODEL_KITS];

        $grade = StorefrontTag::slugify(is_string($product->grade) ? $product->grade : null);
        $typeSlug = StorefrontTag::slugify(is_string($product->type) ? $product->type : null);

        if ($grade === null && $typeSlug !== null) {
            $grade = $typeSlug;
        }

        if ($grade !== null) {
            $tags[] = StorefrontTag::mkGrade($grade);
        }

        $subline = StorefrontTag::slugify(is_string($product->subline) ? $product->subline : null);
        if ($subline === null && $typeSlug !== null && $typeSlug !== $grade) {
            $subline = $typeSlug;
        }

        $sdSubline = $this->resolveSdSublineFromDescription($product);
        if ($sdSubline !== null) {
            $subline = $subline ?? $sdSubline;
        }

        if ($subline !== null) {
            $tags[] = StorefrontTag::mkSubline($subline);
        } elseif ($grade === 'mg') {
            $tags[] = StorefrontTag::MK_LINE_MG_STANDARD;
        }

        $series = StorefrontTag::slugify(is_string($product->series) ? $product->series : null);
        if ($series !== null) {
            $tags[] = StorefrontTag::mkSeries($series);
        }

        foreach ($this->lineTags($product, $grade) as $lineTag) {
            $tags[] = $lineTag;
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return array<int, string>
     */
    private function lineTags(Product $product, ?string $grade): array
    {
        $lines = [];
        $franchise = mb_strtolower(trim((string) ($product->franchise ?? '')));
        $productLine = mb_strtolower(trim((string) ($product->product_line ?? '')));
        $description = mb_strtoupper(trim((string) $product->description));
        $type = mb_strtoupper(trim((string) ($product->type ?? '')));
        $series = mb_strtolower(trim((string) ($product->series ?? '')));
        $sku = mb_strtoupper(trim((string) $product->sku));

        if ($franchise === 'gundam' || $productLine === 'gunpla' || $this->isGunplaGrade($grade)) {
            $lines[] = StorefrontTag::MK_LINE_GUNPLA;
        }

        if (str_contains($description, 'MODEROID')) {
            $lines[] = StorefrontTag::MK_LINE_MODEROID;
        }

        if (
            $type === 'ARMORED CORE'
            || str_contains($series, 'armored core')
            || str_contains($description, 'ARMORED CORE')
        ) {
            if ($productLine === '30 minutes missions' || str_contains($description, '30MM')) {
                $lines[] = StorefrontTag::MK_LINE_30MM_ARMORED_CORE;
            }
        }

        if ($this->isThirtyMinutesLabelAccessory($productLine, $type, $description)) {
            $lines[] = StorefrontTag::MK_LINE_30MM_ACCESSORIES;
        }

        if ($this->isSnaa($productLine, $description, $sku)) {
            $lines[] = StorefrontTag::MK_LINE_SNAA;
        }

        if ($franchise === 'one piece' || str_contains($description, 'ONE PIECE')) {
            $lines[] = StorefrontTag::MK_LINE_ONE_PIECE;
        }

        if ($franchise === 'eureka seven' || str_contains($series, 'eureka_seven') || str_contains($description, 'EUREKA SEVEN')) {
            $lines[] = StorefrontTag::MK_LINE_EUREKA_SEVEN;
        }

        if ($productLine === 'mechatrowego' || str_contains($description, 'MECHATROWEGO')) {
            $lines[] = StorefrontTag::MK_LINE_MECHATROWEGO;
        }

        if ($grade === 'plamax' || $type === 'PLAMAX' || str_contains($description, 'PLAMAX')) {
            $lines[] = StorefrontTag::MK_LINE_PLAMAX;
        }

        if ($this->isEvangelion($franchise, $series, $description, $grade, $type, $productLine)) {
            $lines[] = StorefrontTag::MK_LINE_EVANGELION;
        }

        return $lines;
    }

    private function isThirtyMinutesLabelAccessory(string $productLine, string $type, string $description): bool
    {
        if ($productLine === 'gunpla' || str_contains($description, 'OPTION PARTS SET GUNPLA')) {
            return false;
        }

        if (! in_array($productLine, ['30 minutes missions', '30 minutes sisters'], true)) {
            return false;
        }

        if (! str_contains($description, 'OPTION')) {
            return false;
        }

        if (str_starts_with($description, 'CUSTOMIZE ')) {
            return false;
        }

        return true;
    }

    private function isSnaa(string $productLine, string $description, string $sku): bool
    {
        if ($productLine === 'snaa') {
            return true;
        }

        if (str_contains($description, 'SNAA')) {
            return true;
        }

        return str_starts_with($sku, 'SNAA-') || str_starts_with($sku, 'JS-SNAA');
    }

    private function isEvangelion(
        string $franchise,
        string $series,
        string $description,
        ?string $grade,
        string $type,
        string $productLine,
    ): bool {
        if ($this->isCcsToys($grade, $type, $productLine)) {
            return false;
        }

        if (str_contains($franchise, 'evangelion')) {
            return true;
        }

        if (str_contains($series, 'evangelion')) {
            return true;
        }

        return str_contains($description, 'EVANGELION')
            || preg_match('/\bEVA[\s-]?0?\d/', $description) === 1;
    }

    private function isCcsToys(?string $grade, string $type, string $productLine): bool
    {
        return $grade === 'ccs_toys'
            || $type === 'CCS TOYS'
            || $productLine === 'ccs toys';
    }

    private function resolveSdSublineFromDescription(Product $product): ?string
    {
        $description = mb_strtoupper(trim((string) $product->description));
        if ($description === '') {
            return null;
        }

        if (str_contains($description, 'CROSS SILHOUETTE')) {
            return 'cross_silhouette';
        }

        if (str_contains($description, 'BB SENSHI')) {
            return 'bb_senshi';
        }

        if (str_contains($description, 'G GENERATION') || str_contains($description, 'G-GENERATION')) {
            return 'g_generation';
        }

        if (str_contains($description, 'EX-STANDARD') || str_contains($description, 'EX STANDARD')) {
            return 'ex_standard';
        }

        return null;
    }

    private function isGunplaGrade(?string $grade): bool
    {
        return in_array($grade, [
            'hg',
            'mg',
            'rg',
            'sd',
            'eg',
            'pg',
            'mgex',
            'mgsd',
            're',
            'fm',
            'mega',
            'ng',
            'g',
        ], true);
    }
}
