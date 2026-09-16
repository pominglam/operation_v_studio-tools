<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

/**
 * Model kit mega-menu → single hub (/collections/model-kits) with ovs_mk_* URL prefilters.
 *
 * @phpstan-type HubPrefilter array{grades?: list<string>, series?: list<string>, franchises?: list<string>, lines?: list<string>}
 */
final class ModelKitCollectionFilterHubNavigation
{
    public const HUB_HANDLE = 'model-kits';

    /** @var array<string, HubPrefilter> */
    private const SPECIAL_PREFILTERS = [
        'gundam-universal-century' => ['series' => ['uc-all']],
        'gundam-alternate-universes' => ['series' => ['au-all']],
        'master-grade-mg' => ['grades' => ['mg']],
        'gunpla-option-parts' => ['grades' => ['option-parts']],
        'action-base' => ['grades' => ['action-base']],
    ];

    /** Handles that stay on their own collection (not redirected to hub). */
    private const NON_REDIRECT_HANDLES = [
        'latest-arrivals',
    ];

    /** Shelves with a working Shopify smart collection — mega menu uses /collections/{handle}. */
    private const ABSOLUTE_SHELF_HANDLES = [
        'gunpla-option-parts',
        'action-base',
    ];

    /** Shelves kept in ERP/catalog but hidden from mega menu + facet grade list until stocked. */
    private const CUSTOMER_HIDDEN_CATALOG_KEYS = [
        'sd-g-generation',
    ];

    /** Hub-only grade prefilters (no dedicated Shopify collection shelf). */
    private const HUB_GRADE_PREFILTERS = [
        're-100' => ['grades' => ['re_100']],
        'full-mechanics' => ['grades' => ['fm']],
        'mega-size' => ['grades' => ['mega_size']],
    ];

    /** @var array<string, string> */
    private const HUB_GRADE_TITLES = [
        're-100' => 'RE/100',
        'full-mechanics' => 'Full Mechanics',
        'mega-size' => 'Mega Size',
    ];

    /**
     * @return array<string, HubPrefilter>
     */
    public static function prefiltersByHandle(): array
    {
        $result = [];
        $gradeDefaults = ModelKitCollectionFilterShelfDefaults::catalogKeyDefaults();

        foreach (ModelKitShelfCatalog::shelves() as $catalogKey => $meta) {
            if (in_array($catalogKey, self::CUSTOMER_HIDDEN_CATALOG_KEYS, true)) {
                continue;
            }

            $handle = $meta['handle'];
            if ($handle === self::HUB_HANDLE || in_array($handle, self::NON_REDIRECT_HANDLES, true)) {
                continue;
            }

            if (isset(self::SPECIAL_PREFILTERS[$catalogKey])) {
                $result[$handle] = self::SPECIAL_PREFILTERS[$catalogKey];

                continue;
            }

            if (isset($gradeDefaults[$catalogKey])) {
                $pref = array_filter(
                    $gradeDefaults[$catalogKey],
                    static fn (mixed $value): bool => is_array($value) && $value !== [],
                );
                if ($pref !== []) {
                    $result[$handle] = $pref;
                }

                continue;
            }

            $fromTags = self::prefiltersFromShelfMeta($meta);
            if ($fromTags !== []) {
                $result[$handle] = $fromTags;
            }
        }

        return array_merge($result, self::HUB_GRADE_PREFILTERS);
    }

    /**
     * @return array<string, string> shelf handle → display title
     */
    public static function shelfTitlesByHandle(): array
    {
        $titles = [];
        foreach (ModelKitShelfCatalog::shelves() as $catalogKey => $meta) {
            if (in_array($catalogKey, self::CUSTOMER_HIDDEN_CATALOG_KEYS, true)) {
                continue;
            }

            $titles[$meta['handle']] = $meta['title'];
        }

        return array_merge($titles, self::HUB_GRADE_TITLES);
    }

    /**
     * Liquid {%- capture ... -%} block for ovs-model-kits-mega-menu-poc.liquid (between MK_HUB_URLS markers).
     */
    public static function liquidUrlCaptureBlock(): string
    {
        $lines = [];
        $hubPath = '/collections/'.self::HUB_HANDLE;

        foreach (self::prefiltersByHandle() as $handle => $pref) {
            $var = self::liquidVariableNameForHandle($handle);
            if (in_array($handle, self::ABSOLUTE_SHELF_HANDLES, true)) {
                $lines[] = "{%- capture {$var} -%}/collections/{$handle}{%- endcapture -%}";

                continue;
            }

            $query = self::buildQueryString($pref);
            $lines[] = "{%- capture {$var} -%}{$hubPath}{$query}{%- endcapture -%}";
        }

        $lines[] = "{%- capture gunpla_collection_url -%}{$hubPath}{%- endcapture -%}";

        return implode("\n", $lines);
    }

    /**
     * @param  HubPrefilter  $pref
     */
    public static function buildQueryString(array $pref): string
    {
        $params = [];
        if (! empty($pref['grades'])) {
            $params[] = 'ovs_mk_grade='.rawurlencode(implode(',', $pref['grades']));
        }
        if (! empty($pref['series'])) {
            $params[] = 'ovs_mk_series='.rawurlencode(implode(',', $pref['series']));
        }
        if (! empty($pref['franchises'])) {
            $params[] = 'ovs_mk_franchise='.rawurlencode(implode(',', $pref['franchises']));
        }
        if (! empty($pref['lines'])) {
            $params[] = 'ovs_mk_line='.rawurlencode(implode(',', $pref['lines']));
        }

        return $params === [] ? '' : '?'.implode('&', $params);
    }

    /**
     * @param  array{handle: string, title: string, tag?: string, tags?: list<string>}  $meta
     * @return HubPrefilter
     */
    private static function prefiltersFromShelfMeta(array $meta): array
    {
        $tags = [];
        if (isset($meta['tag'])) {
            $tags[] = $meta['tag'];
        }
        if (isset($meta['tags'])) {
            $tags = array_merge($tags, $meta['tags']);
        }

        $grades = [];
        $series = [];
        $franchises = [];
        $lines = [];

        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'mk:series:')) {
                $key = substr($tag, strlen('mk:series:'));
                if (self::isFranchiseSeriesKey($key)) {
                    $franchises[] = $key;
                } else {
                    $series[] = $key;
                }

                continue;
            }

            if (str_starts_with($tag, 'mk:subline:')) {
                $grades[] = substr($tag, strlen('mk:subline:'));

                continue;
            }

            if (str_starts_with($tag, 'mk:grade:')) {
                $key = substr($tag, strlen('mk:grade:'));
                if (in_array($key, ['30mm', '30ms', '30mf', '30mp'], true)) {
                    $lines[] = $key;
                } elseif (self::isBrandGradeKey($key)) {
                    $franchises[] = $key;
                } else {
                    $grades[] = $key;
                }

                continue;
            }

            if (str_starts_with($tag, 'mk:line:')) {
                $lineKey = substr($tag, strlen('mk:line:'));
                if (self::isBrandLineKey($lineKey)) {
                    $franchises[] = $lineKey;
                } else {
                    $lines[] = str_replace('-', '_', $lineKey);
                }
            }
        }

        $pref = [];
        if ($grades !== []) {
            $pref['grades'] = array_values(array_unique($grades));
        }
        if ($series !== []) {
            $pref['series'] = array_values(array_unique($series));
        }
        if ($franchises !== []) {
            $pref['franchises'] = array_values(array_unique($franchises));
        }
        if ($lines !== []) {
            $pref['lines'] = array_values(array_unique($lines));
        }

        return $pref;
    }

    private static function isFranchiseSeriesKey(string $key): bool
    {
        return in_array($key, [
            'patlabor',
            'macross_delta',
            'armored_trooper_votoms',
            'mazinger',
            'getter_robo',
            'kotetsu_jeeg',
            'super_robot_wars',
            'armored_core',
            'doraemon',
            'sakura_wars',
            'linebarrels_of_iron',
            'eureka_seven',
            'one_piece',
        ], true);
    }

    private static function isBrandGradeKey(string $key): bool
    {
        return in_array($key, [
            'pokemon',
            'kotobukiya',
            'keroro',
        ], true);
    }

    private static function isBrandLineKey(string $key): bool
    {
        return in_array($key, [
            'moderoid',
            'snaa',
            'plamax',
            'mechatrowego',
            'evangelion',
            'one_piece',
        ], true);
    }

    private static function liquidVariableNameForHandle(string $handle): string
    {
        $map = [
            'entry-grade-eg' => 'entry_grade_url',
            'high-grade-hg' => 'hg_url',
            'real-grade-rg' => 'rg_url',
            'perfect-grade-pg' => 'perfect_grade_url',
            'master-grade-mg' => 'mg_collection_url',
            'mg-standard' => 'mg_standard_collection_url',
            'mg-ver-ka' => 'mg_ver_ka_collection_url',
            'master-grade-sd-mgsd' => 'mgsd_collection_url',
            'sd-gundam' => 'sd_gundam_url',
            'sd-ex-standard' => 'sd_ex_standard_url',
            'sd-cross-silhouette' => 'sd_cross_silhouette_url',
            'sd-world-heroes' => 'sd_world_heroes_url',
            'sd-bb-senshi' => 'sd_bb_url',
            'sd-build-fighters' => 'sd_build_fighters_url',
            're-100' => 're_100_url',
            'full-mechanics' => 'fm_url',
            'mega-size' => 'mega_size_url',
            'sd-gunpla-kun' => 'sd_gunpla_kun_url',
            'gunpla-option-parts' => 'gunpla_option_parts_url',
            'action-base' => 'action_bases_url',
            'hg-universal-century' => 'hguc_url',
            'hg-gundam-seed' => 'hgce_url',
            'hg-after-colony' => 'hgac_url',
            'hg-iron-blooded-orphans' => 'hgibo_url',
            'hg-build-fighters' => 'hgbf_url',
            'hg-build-divers' => 'hgbd_url',
            'gundam-universal-century' => 'gundam_uc_series_url',
            'gundam-alternate-universes' => 'gundam_au_series_url',
            'gundam-other-uc-series' => 'gundam_other_uc_series_url',
            'gundam-other-au-series' => 'gundam_other_au_series_url',
            'gundam-mobile-suit-gundam' => 'gundam_0079_series_url',
            'gundam-zeta' => 'gundam_zeta_series_url',
            'gundam-zz' => 'gundam_zz_series_url',
            'gundam-chars-counterattack' => 'gundam_cca_series_url',
            'gundam-0080' => 'gundam_0080_series_url',
            'gundam-f91' => 'gundam_f91_series_url',
            'gundam-0083' => 'gundam_0083_series_url',
            'gundam-unicorn' => 'gundam_unicorn_series_url',
            'mgex' => 'mgex_collection_url',
            'gundam-seed' => 'gundam_seed_series_url',
            'gundam-wing' => 'gundam_wing_series_url',
            'gundam-00' => 'gundam_00_series_url',
            'g-gundam' => 'g_gundam_series_url',
            'gundam-build-fighters' => 'gundam_build_fighters_series_url',
            'gundam-build-divers' => 'gundam_build_divers_series_url',
            'gundam-age' => 'gundam_age_series_url',
            'gundam-hathaway' => 'gundam_hathaway_series_url',
            'gundam-iron-blooded-orphans' => 'gundam_ibo_series_url',
            'gundam-witch-from-mercury' => 'gundam_mercury_series_url',
            'gundam-reconguista-in-g' => 'gundam_reconguista_in_g_series_url',
            'gundam-x' => 'gundam_x_series_url',
            'gundam-requiem-for-vengeance' => 'gundam_requiem_for_vengeance_series_url',
            '30-minutes-missions' => 'thirty_mm_url',
            '30-minutes-armored-core' => 'thirty_mm_armored_core_url',
            '30-minutes-sisters' => 'thirty_ms_url',
            '30-minutes-fantasy' => 'thirty_mf_url',
            '30-minutes-preference' => 'thirty_mp_url',
            '30-minutes-accessories' => 'thirty_ml_accessories_url',
            'pokemon' => 'pokemon_url',
            'kotobukiya' => 'kotobukiya_url',
            'moderoid' => 'moderoid_url',
            'keroro' => 'keroro_url',
            'snaa' => 'snaa_url',
            'mechatrowego' => 'mechatrowego_url',
            'plamax' => 'plamax_url',
            'evangelion' => 'evangelion_url',
            'other-series' => 'other_series_url',
            'patlabor' => 'patlabor_url',
            'macross-delta' => 'macross_delta_url',
            'armored-trooper-votoms' => 'armored_trooper_votoms_url',
            'mazinger' => 'mazinger_url',
            'getter-robo' => 'getter_robo_url',
            'kotetsu-jeeg' => 'kotetsu_jeeg_url',
            'super-robot-wars' => 'super_robot_wars_url',
            'armored-core' => 'armored_core_url',
            'doraemon' => 'doraemon_url',
            'sakura-wars' => 'sakura_wars_url',
            'linebarrels-of-iron' => 'linebarrels_of_iron_url',
            'eureka-seven' => 'eureka_seven_url',
            'one-piece' => 'one_piece_url',
        ];

        return $map[$handle] ?? str_replace('-', '_', $handle).'_url';
    }
}
