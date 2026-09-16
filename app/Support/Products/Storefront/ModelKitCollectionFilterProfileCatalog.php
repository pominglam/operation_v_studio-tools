<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

/**
 * Maps each {@see ModelKitShelfCatalog} shelf to a storefront collection-filter profile.
 */
final class ModelKitCollectionFilterProfileCatalog
{
    /**
     * @return array<string, list<string>>
     */
    public static function profileGroups(): array
    {
        return [
            'gunpla-hub' => ['grade-gunpla', 'series-gundam', 'line-30mm', 'franchise-more', 'brand-other', 'price'],
            'uc-hub' => ['grade-gunpla', 'series-uc', 'price'],
            'au-hub' => ['grade-gunpla', 'series-au', 'price'],
            'uc-other-hub' => ['grade-gunpla', 'series-uc-other', 'price'],
            'au-other-hub' => ['grade-gunpla', 'series-au-other', 'price'],
            'grade-simple' => ['grade-gunpla', 'series-gundam', 'price'],
            'subline-shelf' => ['grade-gunpla', 'series-gundam', 'price'],
            'series-leaf' => ['grade-gunpla', 'price'],
            'franchise-leaf' => ['grade-gunpla', 'price'],
            'franchise-rollup' => ['franchise', 'grade-gunpla', 'price'],
            '30mm-hub' => ['line-30mm', 'price'],
            '30mm-leaf' => ['line-30mm', 'price'],
            'brand-leaf' => ['grade-gunpla', 'price'],
            'accessories' => ['price'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            'grade-gunpla' => 'Grade',
            'series-uc' => 'Gundam series (UC)',
            'series-au' => 'Gundam series (AU)',
            'series-uc-other' => 'UC series',
            'series-au-other' => 'AU series',
            'series-gundam' => 'Gundam series',
            'franchise' => 'Other series',
            'franchise-more' => 'Other series',
            'brand-other' => 'Other model kits',
            'line-30mm' => '30 minutes label',
            'price' => 'Price',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function urlParams(): array
    {
        return [
            'grade-gunpla' => 'ovs_mk_grade',
            'series-uc' => 'ovs_mk_series',
            'series-au' => 'ovs_mk_series',
            'series-uc-other' => 'ovs_mk_series',
            'series-au-other' => 'ovs_mk_series',
            'series-gundam' => 'ovs_mk_series',
            'franchise' => 'ovs_mk_franchise',
            'franchise-more' => 'ovs_mk_franchise',
            'brand-other' => 'ovs_mk_franchise',
            'line-30mm' => 'ovs_mk_line',
            'price' => 'ovs_mk_price',
        ];
    }

    /**
     * @return array<string, string> catalog key → profile slug
     */
    public static function catalogKeyProfiles(): array
    {
        $profiles = [
            'model-kits' => 'gunpla-hub',
            'gundam-universal-century' => 'uc-hub',
            'gundam-alternate-universes' => 'au-hub',
            'gundam-other-uc-series' => 'uc-other-hub',
            'gundam-other-au-series' => 'au-other-hub',
        ];

        foreach (['high-grade-hg', 'master-grade-mg', 'sd-gundam', 'entry-grade-eg', 'real-grade-rg', 'perfect-grade-pg'] as $handle) {
            $profiles[$handle] = 'grade-simple';
        }
        foreach ([
            'hg-universal-century', 'hg-gundam-seed', 'hg-after-colony', 'hg-iron-blooded-orphans',
            'hg-build-fighters', 'hg-build-divers',
        ] as $handle) {
            $profiles[$handle] = 'subline-shelf';
        }
        foreach (['mg-standard', 'mg-ver-ka', 'mgex', 'mgsd'] as $handle) {
            $profiles[$handle] = 'subline-shelf';
        }
        foreach ([
            'sd-ex-standard', 'sd-cross-silhouette', 'sd-world-heroes', 'sd-bb-senshi',
            'sd-g-generation', 'sd-build-fighters', 'sd-gunpla-kun',
        ] as $handle) {
            $profiles[$handle] = 'subline-shelf';
        }
        foreach ([
            'gundam-mobile-suit-gundam', 'gundam-zeta', 'gundam-zz', 'gundam-chars-counterattack', 'gundam-0080',
            'gundam-f91', 'gundam-0083', 'gundam-08th-ms-team', 'gundam-the-origin', 'gundam-thunderbolt',
            'gundam-narrative', 'gundam-sentinel', 'gundam-unicorn', 'gundam-seed', 'gundam-wing', 'gundam-00',
            'g-gundam', 'gundam-build-fighters', 'gundam-build-divers', 'gundam-age', 'gundam-hathaway',
            'gundam-iron-blooded-orphans', 'gundam-witch-from-mercury', 'gundam-reconguista-in-g', 'gundam-x',
            'gundam-requiem-for-vengeance',
        ] as $handle) {
            $profiles[$handle] = 'series-leaf';
        }
        foreach ([
            'patlabor', 'macross-delta', 'armored-trooper-votoms', 'mazinger', 'getter-robo', 'kotetsu-jeeg',
            'super-robot-wars', 'armored-core', 'doraemon', 'sakura-wars', 'linebarrels-of-iron',
        ] as $handle) {
            $profiles[$handle] = 'franchise-leaf';
        }
        $profiles['other-series'] = 'franchise-rollup';
        foreach (['30-minutes-missions'] as $handle) {
            $profiles[$handle] = '30mm-hub';
        }
        foreach ([
            '30-minutes-armored-core', '30-minutes-sisters', '30-minutes-fantasy',
            '30-minutes-preference', '30-minutes-accessories',
        ] as $handle) {
            $profiles[$handle] = '30mm-leaf';
        }
        foreach ([
            'pokemon', 'kotobukiya', 'moderoid', 'keroro', 'snaa', 'one-piece', 'eureka-seven',
            'mechatrowego', 'plamax', 'evangelion',
        ] as $handle) {
            $profiles[$handle] = 'brand-leaf';
        }
        foreach (['gunpla-option-parts', 'action-base'] as $handle) {
            $profiles[$handle] = 'accessories';
        }

        return $profiles;
    }

    /**
     * @return array<string, string> Shopify collection handle → profile slug
     */
    public static function handlesByProfile(): array
    {
        $catalogProfiles = self::catalogKeyProfiles();
        $shelves = ModelKitShelfCatalog::shelves();

        foreach (array_keys($shelves) as $catalogKey) {
            if (! isset($catalogProfiles[$catalogKey])) {
                throw new \RuntimeException("Missing filter profile for catalog key: {$catalogKey}");
            }
        }

        if (count($catalogProfiles) !== count($shelves)) {
            throw new \RuntimeException(
                'Filter profile count '.count($catalogProfiles).' != shelf count '.count($shelves),
            );
        }

        $byHandle = [];
        foreach ($shelves as $catalogKey => $meta) {
            $byHandle[$meta['handle']] = $catalogProfiles[$catalogKey];
        }

        return [
            ...$byHandle,
            ...self::extraFilterHandles(),
        ];
    }

    /**
     * Non-shelf collections that reuse hub filter profiles (e.g. latest-arrivals).
     *
     * @return array<string, string>
     */
    public static function extraFilterHandles(): array
    {
        return [
            'latest-arrivals' => 'gunpla-hub',
        ];
    }
}
