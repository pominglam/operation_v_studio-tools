<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;
use App\Support\Products\ModelKitAccessoryKind;
use App\Support\Products\ModelKitSeriesCatalog;

final class ModelKitStorefrontTagResolver
{
    /**
     * Absolute model-kit taxonomy tags from ERP canonical columns (not title search).
     *
     * @return array<int, string>
     */
    public function tagsForProduct(Product $product): array
    {
        if ($this->isWorkshopToolNotKit($product)) {
            return [];
        }

        if ($this->isGunplaActionBase($product)) {
            return [
                StorefrontTag::MK_DEPT_MODEL_KITS,
                StorefrontTag::MK_LINE_GUNPLA,
                StorefrontTag::MK_LINE_ACTION_BASE,
            ];
        }

        if ($this->isGunplaOptionPart($product)) {
            return [
                StorefrontTag::MK_DEPT_MODEL_KITS,
                StorefrontTag::MK_LINE_GUNPLA,
                StorefrontTag::MK_LINE_GUNPLA_OPTION_PARTS,
            ];
        }

        if ($this->isGunplaKun($product)) {
            return [
                StorefrontTag::MK_DEPT_MODEL_KITS,
                StorefrontTag::MK_LINE_GUNPLA,
                StorefrontTag::mkGrade('sd'),
                StorefrontTag::mkSubline('gunpla_kun'),
            ];
        }

        if (mb_strtolower(trim((string) $product->main_type)) !== 'model kit') {
            return [];
        }

        $tags = [StorefrontTag::MK_DEPT_MODEL_KITS];

        $grade = StorefrontTag::slugify(is_string($product->grade) ? $product->grade : null);
        $typeSlug = StorefrontTag::slugify(is_string($product->type) ? $product->type : null);

        if ($grade === null && $typeSlug !== null) {
            $grade = $typeSlug;
        }

        if ($grade === null && $this->isPokemonPlamo($product)) {
            $grade = 'pokemon';
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

        $accessoryMarkers = [
            'EXTENDED ARMAMENT VEHICLE',
            'ARMAMENT VEHICLE',
            'CUSTOMIZE WEAPONS',
            'CUSTOMIZE EFFECT',
            'OPTION',
        ];

        foreach ($accessoryMarkers as $marker) {
            if (str_contains($description, $marker)) {
                return true;
            }
        }

        return false;
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

        return ModelKitSeriesCatalog::textLooksLikeEvangelion($description);
    }

    private function isCcsToys(?string $grade, string $type, string $productLine): bool
    {
        return $grade === 'ccs_toys'
            || $type === 'CCS TOYS'
            || $productLine === 'ccs toys';
    }

    private function resolveSdSublineFromDescription(Product $product): ?string
    {
        return (new ModelKitSdSublineResolver)->resolveSlug(
            is_string($product->subline) ? $product->subline : null,
            is_string($product->type) ? $product->type : null,
            is_string($product->description) ? $product->description : null,
            is_string($product->product_line) ? $product->product_line : null,
        );
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

    private function isGunplaKun(Product $product): bool
    {
        $type = mb_strtoupper(trim((string) ($product->type ?? '')));
        $productLine = StorefrontTag::slugify(is_string($product->product_line) ? $product->product_line : null);
        $description = mb_strtoupper(trim((string) $product->description));
        if ($type === 'KEYCHAIN'
            || $productLine === 'keychains'
            || preg_match('/\b(?:KEYCHAIN|RUBBER MASCOT|MASCOT KEYCHAIN)\b/', $description) === 1
        ) {
            return false;
        }

        if ($type === 'KUN DX') {
            return true;
        }

        $subline = StorefrontTag::slugify(is_string($product->subline) ? $product->subline : null);
        if ($subline === 'gunpla_kun') {
            return true;
        }

        return preg_match('/\b(?:GUNPLA|ZAKUPLA|CHARZAKU)-KUN(?:\s+DX)?\b/', $description) === 1;
    }

    private function isWorkshopToolNotKit(Product $product): bool
    {
        $department = StorefrontTag::slugify(is_string($product->department) ? $product->department : null);
        if (in_array($department, ['tools', 'paints', 'supplies'], true)) {
            return true;
        }

        $shelf = mb_strtolower(trim((string) ($product->workshop_shelf ?? '')));
        if ($shelf === 'cutting mats') {
            return true;
        }

        return preg_match('/\bCUTTING MAT\b/', mb_strtoupper(trim((string) $product->description))) === 1;
    }

    private function isGunplaActionBase(Product $product): bool
    {
        $type = mb_strtoupper(trim((string) ($product->type ?? '')));
        $productLine = mb_strtolower(trim((string) ($product->product_line ?? '')));
        $description = mb_strtoupper(trim((string) $product->description));
        $accessoryKind = trim((string) ($product->accessory_kind ?? ''));

        if (in_array($productLine, ['30 minutes missions', '30 minutes sisters', '30 minutes fantasy'], true)) {
            return false;
        }

        if ($this->isThirtyMinutesLabelAccessory($productLine, $type, $description)) {
            return false;
        }

        if ($type === 'ACTION BASE') {
            return true;
        }

        if (
            ($type === 'SYSTEM BASE' || preg_match('/\bSYSTEM BASE\b/', $description) === 1)
            && in_array($productLine, ['action base', 'gunpla'], true)
        ) {
            return true;
        }

        if (
            preg_match('/\bBUILDERS PARTS SYSTEM BASE\b/', $description) === 1
            && $productLine === 'action base'
        ) {
            return true;
        }

        return $accessoryKind === ModelKitAccessoryKind::DISPLAY_STAND
            && $productLine === 'action base';
    }

    private function isGunplaOptionPart(Product $product): bool
    {
        $accessoryKind = trim((string) ($product->accessory_kind ?? ''));
        $productLine = mb_strtolower(trim((string) ($product->product_line ?? '')));
        $type = mb_strtoupper(trim((string) ($product->type ?? '')));
        $description = mb_strtoupper(trim((string) $product->description));
        $sku = mb_strtoupper(trim((string) $product->sku));

        if ($accessoryKind === ModelKitAccessoryKind::OPTION_PARTS && $productLine === 'gunpla') {
            return true;
        }

        if ($accessoryKind === ModelKitAccessoryKind::OPTION_PARTS && $productLine === 'option system') {
            return true;
        }

        if (preg_match('/^OP-\d/', $sku) === 1 || str_starts_with($sku, 'WAVOP-')) {
            return true;
        }

        if (preg_match('/\bOPTION SYSTEM\b/', $description) === 1) {
            return true;
        }

        if ($accessoryKind === ModelKitAccessoryKind::DETAIL_PARTS && $productLine === 'builders parts hd') {
            return true;
        }

        if (($type === 'OPTION PARTS SET' || $type === 'OPTION PARTS') && $productLine === 'gunpla') {
            return true;
        }

        if (preg_match('/\bOPTION PARTS SET GUNPLA\b/', $description) === 1) {
            return true;
        }

        if (preg_match('/\b(?:MS HAND|MS SIGHT|SIGHT LENS)\b/', $description) === 1
            && (str_starts_with($sku, 'BPHD-') || $productLine === 'builders parts hd')
        ) {
            return true;
        }

        return false;
    }

    private function isPokemonPlamo(Product $product): bool
    {
        $productLine = mb_strtolower(trim((string) ($product->product_line ?? '')));
        $franchise = mb_strtolower(trim((string) ($product->franchise ?? '')));

        return $productLine === 'pokémon plamo collection'
            || str_contains($productLine, 'pokemon')
            || $franchise === 'pokémon'
            || $franchise === 'pokemon';
    }
}
