<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Support\Products\Storefront\StorefrontTag;

final class WorkshopFacetsFromStorefrontTags
{
    /**
     * @param  array<int, string>  $tags
     * @return array<string, string|array<int, string>>
     */
    public static function parse(array $tags): array
    {
        /** @var array<string, string|array<int, string>> $facets */
        $facets = [];

        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag === '' || ! str_starts_with($tag, 'ts:')) {
                continue;
            }

            if ($tag === StorefrontTag::CUT_NIPPER) {
                $facets['cut_category'] = 'nipper';
            } elseif ($tag === StorefrontTag::CUT_KNIFE) {
                $facets['cut_category'] = 'knife';
            } elseif ($tag === StorefrontTag::CUT_BLADE) {
                $facets['cut_category'] = 'blade';
            } elseif ($tag === 'ts:cut:nipper-beginner') {
                self::appendStringList($facets, 'cut_style', 'beginner');
            } elseif (str_starts_with($tag, StorefrontTag::CUT_STYLE_PREFIX)) {
                self::appendStringList($facets, 'cut_style', substr($tag, strlen(StorefrontTag::CUT_STYLE_PREFIX)));
            } elseif (str_starts_with($tag, StorefrontTag::SAND_TYPE_PREFIX)) {
                $facets['sand_type'] = substr($tag, strlen(StorefrontTag::SAND_TYPE_PREFIX));
            } elseif (str_starts_with($tag, 'ts:sand:grit:')) {
                self::appendStringList($facets, 'grit', substr($tag, strlen('ts:sand:grit:')));
            } elseif (str_starts_with($tag, StorefrontTag::BRUSH_TYPE_PREFIX)) {
                $facets['brush_type'] = substr($tag, strlen(StorefrontTag::BRUSH_TYPE_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::DRILL_TYPE_PREFIX)) {
                $facets['drill_type'] = substr($tag, strlen(StorefrontTag::DRILL_TYPE_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::TWEEZER_STYLE_PREFIX)) {
                $facets['tweezer_style'] = substr($tag, strlen(StorefrontTag::TWEEZER_STYLE_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::TWEEZER_LINE_PREFIX)) {
                $facets['tweezer_line'] = substr($tag, strlen(StorefrontTag::TWEEZER_LINE_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::SCRIBING_TYPE_PREFIX)) {
                $facets['scribing_type'] = substr($tag, strlen(StorefrontTag::SCRIBING_TYPE_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::ADHESIVE_TYPE_PREFIX)) {
                $facets['adhesive_type'] = substr($tag, strlen(StorefrontTag::ADHESIVE_TYPE_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::AIRBRUSH_ROLE_PREFIX)) {
                $facets['airbrush_role'] = substr($tag, strlen(StorefrontTag::AIRBRUSH_ROLE_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::MARKER_TYPE_PREFIX)) {
                $facets['marker_type'] = substr($tag, strlen(StorefrontTag::MARKER_TYPE_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::MARKER_TIP_PREFIX)) {
                $facets['marker_tip'] = substr($tag, strlen(StorefrontTag::MARKER_TIP_PREFIX));
            } elseif ($tag === StorefrontTag::DECAL_SOFTENER) {
                $facets['decal_product'] = 'softener';
            } elseif ($tag === StorefrontTag::DECAL_SHEET) {
                $facets['decal_product'] = 'sheet';
            } elseif (str_starts_with($tag, StorefrontTag::PAINT_PRODUCT_PREFIX)) {
                $facets['paint_product'] = substr($tag, strlen(StorefrontTag::PAINT_PRODUCT_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::PAINT_APP_PREFIX)) {
                self::appendStringList($facets, 'paint_application', substr($tag, strlen(StorefrontTag::PAINT_APP_PREFIX)));
            } elseif (str_starts_with($tag, StorefrontTag::PAINT_TYPE_PREFIX)) {
                $facets['paint_type'] = substr($tag, strlen(StorefrontTag::PAINT_TYPE_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::PANEL_LINER_KIND_PREFIX)) {
                $facets['panel_liner_kind'] = substr($tag, strlen(StorefrontTag::PANEL_LINER_KIND_PREFIX));
            } elseif (str_starts_with($tag, StorefrontTag::PANEL_LINER_TYPE_PREFIX)) {
                $facets['panel_liner_type'] = substr($tag, strlen(StorefrontTag::PANEL_LINER_TYPE_PREFIX));
            } elseif ($tag === StorefrontTag::TAPE_MASKING) {
                $facets['tape_type'] = 'masking';
            } elseif ($tag === StorefrontTag::TAPE_SCRIBING) {
                $facets['tape_type'] = 'scribing';
            } elseif (str_starts_with($tag, StorefrontTag::TAPE_WIDTH_PREFIX)) {
                $facets['tape_width_mm'] = substr($tag, strlen(StorefrontTag::TAPE_WIDTH_PREFIX));
            }
        }

        return self::normalizeLists($facets);
    }

    /**
     * @param  array<string, string|array<int, string>>  $facets
     */
    private static function appendStringList(array &$facets, string $key, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $existing = $facets[$key] ?? null;
        if ($existing === null) {
            $facets[$key] = [$value];

            return;
        }

        if (is_string($existing)) {
            $facets[$key] = array_values(array_unique([$existing, $value]));

            return;
        }

        $facets[$key] = array_values(array_unique([...$existing, $value]));
    }

    /**
     * @param  array<string, string|array<int, string>>  $facets
     * @return array<string, string|array<int, string>>
     */
    private static function normalizeLists(array $facets): array
    {
        foreach ($facets as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $facets[$key] = array_values(array_unique(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            ), static fn (string $item): bool => $item !== '')));
        }

        return $facets;
    }
}
