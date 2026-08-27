<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

final class StorefrontTag
{
    public const string DEPT_TAPES = 'ts:dept:tapes';

    public const string TAPE_MASKING = 'ts:tape:masking';

    public const string TAPE_SCRIBING = 'ts:tape:scribing';

    public const string TAPE_WIDTH_PREFIX = 'ts:tape:width:';

    public const string DEPT_DECALS = 'ts:dept:decals';

    public const string DECAL_SOFTENER = 'ts:decal:softener';

    public const string DECAL_SHEET = 'ts:decal:sheet';

    public const string DECAL_BRAND_PREFIX = 'ts:decal:brand:';

    public const string DEPT_SANDING = 'ts:dept:sanding';

    public const string DEPT_CUTTING = 'ts:dept:cutting';

    public const string DEPT_PAINTS = 'ts:dept:paints';

    public const string DEPT_PANEL_LINERS = 'ts:dept:panel-liners';

    public const string PANEL_LINER_KIND_PREFIX = 'ts:panel-liner:kind:';

    public const string PANEL_LINER_TYPE_PREFIX = 'ts:panel-liner:type:';

    public const string PAINT_PRODUCT_PREFIX = 'ts:paint:product:';

    public const string PAINT_APP_PREFIX = 'ts:paint:app:';

    public const string PAINT_TYPE_PREFIX = 'ts:paint:type:';

    public const string DEPT_MARKERS = 'ts:dept:markers';

    public const string DEPT_BRUSHES = 'ts:dept:brushes';

    public const string DEPT_DRILLS = 'ts:dept:drills';

    public const string DEPT_TWEEZERS = 'ts:dept:tweezers';

    public const string DEPT_SCRIBING = 'ts:dept:scribing';

    public const string DEPT_ADHESIVES = 'ts:dept:adhesives';

    public const string DEPT_WORKSHOP_MISC = 'ts:dept:workshop-misc';

    public const string DEPT_AIRBRUSH = 'ts:dept:airbrush';

    public const string DEPT_WEATHERING = 'ts:dept:weathering';

    public const string BRUSH_TYPE_PREFIX = 'ts:brush:type:';

    public const string DRILL_TYPE_PREFIX = 'ts:drill:type:';

    public const string TWEEZER_STYLE_PREFIX = 'ts:tweezer:style:';

    public const string TWEEZER_LINE_PREFIX = 'ts:tweezer:line:';

    public const string SCRIBING_TYPE_PREFIX = 'ts:scribing:type:';

    public const string ADHESIVE_TYPE_PREFIX = 'ts:adhesive:type:';

    public const string AIRBRUSH_ROLE_PREFIX = 'ts:airbrush:role:';

    public const string MARKER_TYPE_PREFIX = 'ts:marker:type:';

    public const string MARKER_TIP_PREFIX = 'ts:marker:tip:';

    public const string MARKER_BRAND_PREFIX = 'ts:marker:brand:';

    public const string CUT_NIPPER = 'ts:cut:nipper';

    public const string CUT_KNIFE = 'ts:cut:knife';

    public const string CUT_BLADE = 'ts:cut:blade';

    public const string CUT_STYLE_PREFIX = 'ts:cut:style:';

    public const string SAND_TYPE_PREFIX = 'ts:sand:type:';

    public const string SAND_GRIT_COARSE = 'ts:sand:grit:coarse';

    public const string SAND_GRIT_MEDIUM = 'ts:sand:grit:medium';

    public const string SAND_GRIT_FINE = 'ts:sand:grit:fine';

    public const string SAND_GRIT_POLISH = 'ts:sand:grit:polish';

    public const string MK_DEPT_MODEL_KITS = 'mk:dept:model-kits';

    public const string MK_GRADE_PREFIX = 'mk:grade:';

    public const string MK_SUBLINE_PREFIX = 'mk:subline:';

    /** Standard MG kits (grade MG, no Ver.Ka / MGEX / MGSD subline). */
    public const string MK_LINE_MG_STANDARD = 'mk:line:mg-standard';

    public const string MK_LINE_GUNPLA = 'mk:line:gunpla';

    public const string MK_LINE_MODEROID = 'mk:line:moderoid';

    public const string MK_LINE_30MM_ARMORED_CORE = 'mk:line:30mm_armored_core';

    public const string MK_LINE_30MM_ACCESSORIES = 'mk:line:30mm_accessories';

    public const string MK_LINE_SNAA = 'mk:line:snaa';

    public const string MK_LINE_ONE_PIECE = 'mk:line:one_piece';

    public const string MK_LINE_EUREKA_SEVEN = 'mk:line:eureka_seven';

    public const string MK_LINE_MECHATROWEGO = 'mk:line:mechatrowego';

    public const string MK_LINE_PLAMAX = 'mk:line:plamax';

    public const string MK_LINE_EVANGELION = 'mk:line:evangelion';

    public const string MK_SERIES_PREFIX = 'mk:series:';

    public static function mkGrade(string $grade): string
    {
        return self::MK_GRADE_PREFIX.$grade;
    }

    public static function mkSubline(string $subline): string
    {
        return self::MK_SUBLINE_PREFIX.$subline;
    }

    public static function mkSeries(string $series): string
    {
        return self::MK_SERIES_PREFIX.$series;
    }

    public static function slugify(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';
        if ($value === '') {
            return null;
        }

        $slug = mb_strtolower($value);
        $slug = str_replace('.', '_', $slug);
        $slug = preg_replace('/\s+/', '_', $slug) ?? '';
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : null;
    }

    public static function tapeWidth(int $widthMm): string
    {
        return self::TAPE_WIDTH_PREFIX.$widthMm;
    }

    public static function sandGrit(string $bucket): string
    {
        return 'ts:sand:grit:'.$bucket;
    }

    public static function sandType(string $type): string
    {
        return self::SAND_TYPE_PREFIX.$type;
    }

    public static function cutStyle(string $style): string
    {
        return self::CUT_STYLE_PREFIX.$style;
    }

    public static function cutCategory(string $category): string
    {
        return match ($category) {
            'nipper' => self::CUT_NIPPER,
            'knife' => self::CUT_KNIFE,
            'blade' => self::CUT_BLADE,
            default => self::CUT_STYLE_PREFIX.$category,
        };
    }

    public static function paintProduct(string $product): string
    {
        return self::PAINT_PRODUCT_PREFIX.$product;
    }

    public static function paintApplication(string $application): string
    {
        return self::PAINT_APP_PREFIX.$application;
    }

    public static function paintType(string $type): string
    {
        return self::PAINT_TYPE_PREFIX.$type;
    }

    public static function panelLinerKind(string $kind): string
    {
        return self::PANEL_LINER_KIND_PREFIX.$kind;
    }

    public static function panelLinerType(string $type): string
    {
        return self::PANEL_LINER_TYPE_PREFIX.$type;
    }

    public static function markerType(string $type): string
    {
        return self::MARKER_TYPE_PREFIX.$type;
    }

    public static function markerTip(string $tip): string
    {
        return self::MARKER_TIP_PREFIX.$tip;
    }

    public static function markerBrand(string $brand): string
    {
        return self::MARKER_BRAND_PREFIX.$brand;
    }

    public static function decalBrand(string $brand): string
    {
        return self::DECAL_BRAND_PREFIX.$brand;
    }

    public static function brushType(string $type): string
    {
        return self::BRUSH_TYPE_PREFIX.$type;
    }

    public static function drillType(string $type): string
    {
        return self::DRILL_TYPE_PREFIX.$type;
    }

    public static function tweezerStyle(string $style): string
    {
        return self::TWEEZER_STYLE_PREFIX.$style;
    }

    public static function tweezerLine(string $line): string
    {
        return self::TWEEZER_LINE_PREFIX.$line;
    }

    public static function scribingType(string $type): string
    {
        return self::SCRIBING_TYPE_PREFIX.$type;
    }

    public static function adhesiveType(string $type): string
    {
        return self::ADHESIVE_TYPE_PREFIX.$type;
    }

    public static function airbrushRole(string $role): string
    {
        return self::AIRBRUSH_ROLE_PREFIX.$role;
    }

    public static function deptTagForDepartment(string $department): ?string
    {
        return match ($department) {
            StorefrontDepartment::TAPES => self::DEPT_TAPES,
            StorefrontDepartment::DECALS => self::DEPT_DECALS,
            StorefrontDepartment::SANDING => self::DEPT_SANDING,
            StorefrontDepartment::CUTTING => self::DEPT_CUTTING,
            StorefrontDepartment::PAINTS => self::DEPT_PAINTS,
            StorefrontDepartment::PANEL_LINERS => self::DEPT_PANEL_LINERS,
            StorefrontDepartment::MARKERS => self::DEPT_MARKERS,
            StorefrontDepartment::BRUSHES => self::DEPT_BRUSHES,
            StorefrontDepartment::DRILLS => self::DEPT_DRILLS,
            StorefrontDepartment::TWEEZERS => self::DEPT_TWEEZERS,
            StorefrontDepartment::SCRIBING => self::DEPT_SCRIBING,
            StorefrontDepartment::ADHESIVES => self::DEPT_ADHESIVES,
            StorefrontDepartment::WORKSHOP_MISC => self::DEPT_WORKSHOP_MISC,
            StorefrontDepartment::AIRBRUSH => self::DEPT_AIRBRUSH,
            StorefrontDepartment::WEATHERING => self::DEPT_WEATHERING,
            default => null,
        };
    }

    /**
     * Tools & Supplies main-menu children: A–Z shelves, then All + Other (footer).
     *
     * @return list<array{handle: string, title: string, footer: bool}>
     */
    public static function toolsAndSuppliesNavMenuChildren(): array
    {
        return [
            ['handle' => 'adhesives', 'title' => 'Adhesives', 'footer' => false],
            ['handle' => 'airbrush', 'title' => 'Airbrush', 'footer' => false],
            ['handle' => 'brushes', 'title' => 'Brushes', 'footer' => false],
            ['handle' => 'decals', 'title' => 'Decals', 'footer' => false],
            ['handle' => 'drills', 'title' => 'Drills & bits', 'footer' => false],
            ['handle' => 'markers', 'title' => 'Markers', 'footer' => false],
            ['handle' => 'nippers-and-knives', 'title' => 'Nippers & knives', 'footer' => false],
            ['handle' => 'panel-liners', 'title' => 'Panel liners', 'footer' => false],
            ['handle' => 'paints', 'title' => 'Paints', 'footer' => false],
            ['handle' => 'sanding', 'title' => 'Sanding', 'footer' => false],
            ['handle' => 'scribing-tools', 'title' => 'Scribing tools', 'footer' => false],
            ['handle' => 'tapes', 'title' => 'Tapes', 'footer' => false],
            ['handle' => 'tweezers', 'title' => 'Tweezers', 'footer' => false],
            ['handle' => 'weathering', 'title' => 'Weathering', 'footer' => false],
            ['handle' => 'tools-and-supplies', 'title' => 'All tools & supplies', 'footer' => true],
            ['handle' => 'workshop-misc', 'title' => 'Other', 'footer' => true],
        ];
    }

    /**
     * Hub sidebar / department filter shelves (A–Z, Other last; excludes hub row).
     *
     * @return list<array{slug: string, tag: string, label: string}>
     */
    public static function toolsAndSuppliesHubDepartments(): array
    {
        $rows = [];
        foreach (self::toolsAndSuppliesNavMenuChildren() as $child) {
            if ($child['footer'] && $child['handle'] === 'tools-and-supplies') {
                continue;
            }

            $slug = self::departmentSlugForCollectionHandle($child['handle']);
            $tag = self::deptTagForDepartment($slug);
            if ($tag === null) {
                continue;
            }

            $rows[] = [
                'slug' => $slug,
                'tag' => $tag,
                'label' => $child['title'],
            ];
        }

        return $rows;
    }

    private static function departmentSlugForCollectionHandle(string $handle): string
    {
        return match ($handle) {
            'scribing-tools' => StorefrontDepartment::SCRIBING,
            'nippers-and-knives' => StorefrontDepartment::CUTTING,
            default => $handle,
        };
    }

    /**
     * @deprecated Use toolsAndSuppliesNavMenuChildren() — kept for callers expecting handle/title only.
     *
     * @return list<array{handle: string, title: string}>
     */
    public static function toolsAndSuppliesNavMenuChildrenLegacyShape(): array
    {
        return array_map(
            static fn (array $child): array => [
                'handle' => $child['handle'],
                'title' => $child['title'],
            ],
            self::toolsAndSuppliesNavMenuChildren(),
        );
    }

    /**
     * @return list<string>
     */
    public static function toolsAndSuppliesHubDepartmentTags(): array
    {
        return array_map(
            static fn (array $row): string => $row['tag'],
            self::toolsAndSuppliesHubDepartments(),
        );
    }
}
