<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

/**
 * Mega-menu model-kit shelves: Shopify handle → smart collection rules on mk:* tags.
 *
 * @phpstan-type ShelfMeta array{handle: string, title: string, tag?: string, tags?: list<string>, disjunctive?: bool}
 */
final class ModelKitShelfCatalog
{
    /**
     * @return array<string, ShelfMeta>
     */
    public static function shelves(): array
    {
        return [
            ...self::gunplaGrades(),
            ...self::masterGradeFamily(),
            ...self::sdGundam(),
            ...self::hgSublines(),
            ...self::gundamSeries(),
            ...self::nonGundamGunplaSeries(),
            ...self::smallSeriesOthers(),
            ...self::thirtyMinutesLabel(),
            ...self::otherModelKits(),
        ];
    }

    /**
     * @return array<string, ShelfMeta>
     */
    private static function gunplaGrades(): array
    {
        return [
            'model-kits' => self::singleTag('model-kits', 'Model kits', StorefrontTag::MK_DEPT_MODEL_KITS),
            'entry-grade-eg' => self::singleTag('entry-grade-eg', 'Entry Grade', 'mk:grade:eg'),
            'high-grade-hg' => self::singleTag('high-grade-hg', 'High Grade (HG)', 'mk:grade:hg'),
            'real-grade-rg' => self::singleTag('real-grade-rg', 'Real Grade (RG)', 'mk:grade:rg'),
            'perfect-grade-pg' => self::singleTag('perfect-grade-pg', 'Perfect Grade (PG)', 'mk:grade:pg'),
        ];
    }

    /**
     * @return array<string, ShelfMeta>
     */
    private static function masterGradeFamily(): array
    {
        return [
            'master-grade-mg' => self::orTags('master-grade-mg', 'Master Grade (MG)', [
                'mk:grade:mg',
                'mk:grade:mgex',
                'mk:grade:mgsd',
            ]),
            'mg-standard' => self::singleTag('mg-standard', 'MG', StorefrontTag::MK_LINE_MG_STANDARD),
            'mg-ver-ka' => self::singleTag('mg-ver-ka', 'MG Ver.Ka', 'mk:subline:ver_ka'),
            'mgex' => self::singleTag('mgex', 'MGEX', 'mk:grade:mgex'),
            'mgsd' => self::singleTag('master-grade-sd-mgsd', 'Master Grade SD (MGSD)', 'mk:grade:mgsd'),
        ];
    }

    /**
     * @return array<string, ShelfMeta>
     */
    private static function sdGundam(): array
    {
        return [
            'sd-gundam' => self::singleTag('sd-gundam', 'SD Gundam', 'mk:grade:sd'),
            'sd-ex-standard' => self::sublineShelf('sd-ex-standard', 'SD EX-Standard', 'ex_standard'),
            'sd-cross-silhouette' => self::sublineShelf('sd-cross-silhouette', 'SD Cross Silhouette', 'cross_silhouette'),
            'sd-world-heroes' => self::sublineShelf('sd-world-heroes', 'SD World Heroes', 'sdw'),
            'sd-bb-senshi' => self::sublineShelf('sd-bb-senshi', 'SD BB Senshi', 'bb_senshi'),
            'sd-g-generation' => self::sublineShelf('sd-g-generation', 'SD G Generation', 'g_generation'),
            'sd-build-fighters' => self::sublineShelf('sd-build-fighters', 'SD Build Fighters', 'sdbf'),
            'sd-gunpla-kun' => self::singleTag('sd-gunpla-kun', 'Gunpla-kun', 'mk:subline:gunpla_kun'),
            'gunpla-option-parts' => self::singleTag('gunpla-option-parts', 'Gunpla Option Parts', StorefrontTag::MK_LINE_GUNPLA_OPTION_PARTS),
            'action-base' => self::singleTag('action-base', 'Action Bases', StorefrontTag::MK_LINE_ACTION_BASE),
        ];
    }

    /**
     * @return array<string, ShelfMeta>
     */
    private static function hgSublines(): array
    {
        return [
            'hg-universal-century' => self::sublineShelf('hg-universal-century', 'HG Universal Century', 'hguc'),
            'hg-gundam-seed' => self::sublineShelf('hg-gundam-seed', 'HG Gundam SEED', 'hgce'),
            'hg-after-colony' => self::sublineShelf('hg-after-colony', 'HG After Colony', 'hgac'),
            'hg-iron-blooded-orphans' => self::sublineShelf('hg-iron-blooded-orphans', 'HG Iron-Blooded Orphans', 'hgibo'),
            'hg-build-fighters' => self::sublineShelf('hg-build-fighters', 'HG Build Fighters', 'hgbf'),
            'hg-build-divers' => self::sublineShelf('hg-build-divers', 'HG Build Divers', 'hgbd'),
        ];
    }

    /**
     * @return array<string, ShelfMeta>
     */
    private static function gundamSeries(): array
    {
        return [
            'gundam-universal-century' => self::orTags('gundam-universal-century', 'Gundam Universal Century', self::universalCenturySeriesTags()),
            'gundam-alternate-universes' => self::orTags('gundam-alternate-universes', 'Gundam Alternate Universes', self::alternateUniverseSeriesTags()),
            'gundam-other-uc-series' => self::orTags('gundam-other-uc-series', 'Other UC series', self::smallUniversalCenturySeriesTags()),
            'gundam-other-au-series' => self::orTags('gundam-other-au-series', 'Other AU series', self::smallAlternateUniverseSeriesTags()),
            'gundam-mobile-suit-gundam' => self::singleTag('gundam-mobile-suit-gundam', 'Mobile Suit Gundam', 'mk:series:mobile_suit_gundam'),
            'gundam-zeta' => self::singleTag('gundam-zeta', 'Zeta Gundam', 'mk:series:zeta_gundam'),
            'gundam-zz' => self::singleTag('gundam-zz', 'Gundam ZZ', 'mk:series:gundam_zz'),
            'gundam-chars-counterattack' => self::singleTag('gundam-chars-counterattack', "Char's Counterattack", 'mk:series:char_s_counterattack'),
            'gundam-0080' => self::singleTag('gundam-0080', 'Gundam 0080: War in the Pocket', 'mk:series:gundam_0080__war_in_the_pocket'),
            'gundam-f91' => self::singleTag('gundam-f91', 'Gundam F91', 'mk:series:gundam_f91'),
            'gundam-0083' => self::singleTag('gundam-0083', 'Gundam 0083: Stardust Memory', 'mk:series:gundam_0083__stardust_memory'),
            'gundam-08th-ms-team' => self::singleTag('gundam-08th-ms-team', 'The 08th MS Team', 'mk:series:the_08th_ms_team'),
            'gundam-the-origin' => self::singleTag('gundam-the-origin', 'Gundam: The Origin', 'mk:series:gundam__the_origin'),
            'gundam-thunderbolt' => self::singleTag('gundam-thunderbolt', 'Gundam Thunderbolt', 'mk:series:gundam_thunderbolt'),
            'gundam-narrative' => self::singleTag('gundam-narrative', 'Gundam Narrative', 'mk:series:gundam_narrative'),
            'gundam-sentinel' => self::singleTag('gundam-sentinel', 'Gundam Sentinel', 'mk:series:gundam_sentinel'),
            'gundam-unicorn' => self::singleTag('gundam-unicorn', 'Gundam Unicorn', 'mk:series:gundam_unicorn'),
            'gundam-seed' => self::orTags('gundam-seed', 'Gundam SEED', [
                'mk:series:gundam_seed',
                'mk:series:gundam_seed_destiny',
                'mk:series:gundam_seed_freedom',
                'mk:series:gundam_seed_astray',
                'mk:series:gundam_seed_stargazer',
            ]),
            'gundam-wing' => self::orTags('gundam-wing', 'Gundam Wing', [
                'mk:series:gundam_wing',
                'mk:series:gundam_wing__endless_waltz',
            ]),
            'gundam-00' => self::orTags('gundam-00', 'Gundam 00', ['mk:series:gundam_00']),
            'g-gundam' => self::singleTag('g-gundam', 'G Gundam', 'mk:series:g_gundam'),
            'gundam-build-fighters' => self::singleTag('gundam-build-fighters', 'Gundam Build Fighters', 'mk:series:gundam_build_fighters'),
            'gundam-build-divers' => self::orTags('gundam-build-divers', 'Gundam Build Divers', [
                'mk:series:gundam_build_divers',
                'mk:series:gundam_build_divers_re_rise',
                'mk:series:gundam_build_metaverse',
                'mk:series:buildmetaverse',
                'mk:series:gundam_breaker_battlogue',
            ]),
            'gundam-age' => self::singleTag('gundam-age', 'Gundam Age', 'mk:series:gundam_age'),
            'gundam-hathaway' => self::orTags('gundam-hathaway', "Gundam Hathaway's Flash", [
                'mk:series:gundam_hathaway',
                'mk:series:hathaway',
            ]),
            'gundam-iron-blooded-orphans' => self::orTags('gundam-iron-blooded-orphans', 'Gundam Iron-Blooded Orphans', [
                'mk:series:iron_blooded_orphans',
            ]),
            'gundam-witch-from-mercury' => self::orTags('gundam-witch-from-mercury', 'Gundam The Witch from Mercury', [
                'mk:series:the_witch_from_mercury',
                'mk:series:mobile_suit_gundam_gquuuuuux',
            ]),
            'gundam-reconguista-in-g' => self::singleTag('gundam-reconguista-in-g', 'Gundam Reconguista in G', 'mk:series:gundam_reconguista_in_g'),
            'gundam-x' => self::singleTag('gundam-x', 'After War Gundam X', 'mk:series:gundam_x'),
            'gundam-requiem-for-vengeance' => self::singleTag('gundam-requiem-for-vengeance', 'Gundam: Requiem for Vengeance', 'mk:series:gundam__requiem_for_vengeance'),
        ];
    }

    /**
     * UC timeline series tags (plus HGUC subline for grade-scoped browse).
     *
     * @return list<string>
     */
    private static function universalCenturySeriesTags(): array
    {
        return [
            'mk:subline:hguc',
            'mk:series:mobile_suit_gundam',
            'mk:series:zeta_gundam',
            'mk:series:gundam_zz',
            'mk:series:char_s_counterattack',
            'mk:series:gundam_0083__stardust_memory',
            'mk:series:gundam_0080__war_in_the_pocket',
            'mk:series:the_08th_ms_team',
            'mk:series:gundam__the_origin',
            'mk:series:gundam_narrative',
            'mk:series:gundam_f91',
            'mk:series:nextuc',
            'mk:series:advance_of_zeta',
            'mk:series:gundam_thunderbolt',
            'mk:series:gundam_sentinel',
            'mk:series:titanomachia',
            'mk:series:gundam_unicorn',
            'mk:series:gundam_hathaway',
            'mk:series:hathaway',
            'mk:series:gundam_reconguista_in_g',
        ];
    }

    /**
     * UC Gundam series with ≤5 kits in nav — mega-menu "Other UC series" shelf.
     *
     * @return list<string>
     */
    private static function smallUniversalCenturySeriesTags(): array
    {
        return [
            'mk:series:gundam_f91',
            'mk:series:the_08th_ms_team',
            'mk:series:gundam__the_origin',
            'mk:series:gundam_thunderbolt',
            'mk:series:gundam_narrative',
            'mk:series:gundam_sentinel',
            'mk:series:gundam_reconguista_in_g',
            'mk:series:gundam__requiem_for_vengeance',
            'mk:series:nextuc',
            'mk:series:advance_of_zeta',
            'mk:series:titanomachia',
        ];
    }

    /**
     * AU Gundam series with ≤5 kits in nav — mega-menu "Other AU series" shelf.
     *
     * @return list<string>
     */
    private static function smallAlternateUniverseSeriesTags(): array
    {
        return [
            'mk:series:gundam_x',
        ];
    }

    /**
     * Non-UC Gundam timelines (fan "AU" grouping) — mega-menu parent shelf only.
     *
     * @return list<string>
     */
    private static function alternateUniverseSeriesTags(): array
    {
        return [
            'mk:series:g_gundam',
            'mk:series:gundam_wing',
            'mk:series:gundam_wing__endless_waltz',
            'mk:series:gundam_seed',
            'mk:series:gundam_seed_destiny',
            'mk:series:gundam_seed_freedom',
            'mk:series:gundam_seed_astray',
            'mk:series:gundam_seed_stargazer',
            'mk:series:gundam_00',
            'mk:series:gundam_age',
            'mk:series:gundam_build_fighters',
            'mk:series:iron_blooded_orphans',
            'mk:series:gundam_build_divers',
            'mk:series:gundam_build_divers_re_rise',
            'mk:series:gundam_build_metaverse',
            'mk:series:buildmetaverse',
            'mk:series:gundam_breaker_battlogue',
            'mk:series:the_witch_from_mercury',
            'mk:series:mobile_suit_gundam_gquuuuuux',
        ];
    }

    /**
     * Bandai Gunpla (and similar) non-Gundam series — ERP `series` → `mk:series:*`.
     *
     * @return array<string, ShelfMeta>
     */
    private static function nonGundamGunplaSeries(): array
    {
        return [
            'patlabor' => self::singleTag('patlabor', 'Patlabor', 'mk:series:patlabor'),
            'macross-delta' => self::singleTag('macross-delta', 'Macross Delta', 'mk:series:macross_delta'),
            'armored-trooper-votoms' => self::singleTag('armored-trooper-votoms', 'Armored Trooper Votoms', 'mk:series:armored_trooper_votoms'),
            'mazinger' => self::singleTag('mazinger', 'Mazinger', 'mk:series:mazinger'),
            'getter-robo' => self::singleTag('getter-robo', 'Getter Robo', 'mk:series:getter_robo'),
            'kotetsu-jeeg' => self::singleTag('kotetsu-jeeg', 'Kotetsu Jeeg', 'mk:series:kotetsu_jeeg'),
            'super-robot-wars' => self::singleTag('super-robot-wars', 'Super Robot Wars', 'mk:series:super_robot_wars'),
            'armored-core' => self::singleTag('armored-core', 'Armored Core', 'mk:series:armored_core'),
            'doraemon' => self::singleTag('doraemon', 'Doraemon', 'mk:series:doraemon'),
            'sakura-wars' => self::singleTag('sakura-wars', 'Sakura Wars', 'mk:series:sakura_wars'),
            'linebarrels-of-iron' => self::singleTag('linebarrels-of-iron', 'Linebarrels of Iron', 'mk:series:linebarrels_of_iron'),
        ];
    }

    /**
     * Non-Gundam franchises with fewer than five kits — single mega-menu "Others" shelf.
     *
     * @return array<string, ShelfMeta>
     */
    private static function smallSeriesOthers(): array
    {
        return [
            'other-series' => self::orTags('other-series', 'Other series', self::smallFranchiseSeriesTags()),
        ];
    }

    /**
     * @return list<string>
     */
    private static function smallFranchiseSeriesTags(): array
    {
        return [
            'mk:series:doraemon',
            'mk:series:mazinger',
            'mk:series:getter_robo',
            'mk:series:kotetsu_jeeg',
            'mk:series:patlabor',
            'mk:series:macross_delta',
            'mk:series:armored_trooper_votoms',
            'mk:series:sakura_wars',
            'mk:series:linebarrels_of_iron',
            'mk:series:eureka_seven',
            StorefrontTag::MK_LINE_EUREKA_SEVEN,
            'mk:series:one_piece',
            StorefrontTag::MK_LINE_ONE_PIECE,
        ];
    }

    /**
     * @return array<string, ShelfMeta>
     */
    private static function thirtyMinutesLabel(): array
    {
        return [
            '30-minutes-missions' => self::singleTag('30-minutes-missions', '30 Minutes Missions', 'mk:grade:30mm'),
            '30-minutes-armored-core' => self::singleTag('30-minutes-armored-core', '30 Minutes Missions Armored Core', 'mk:line:30mm_armored_core'),
            '30-minutes-sisters' => self::singleTag('30-minutes-sisters', '30 Minutes Sisters', 'mk:grade:30ms'),
            '30-minutes-fantasy' => self::singleTag('30-minutes-fantasy', '30 Minutes Fantasy', 'mk:grade:30mf'),
            '30-minutes-preference' => self::singleTag('30-minutes-preference', '30 Minutes Preference', 'mk:grade:30mp'),
            '30-minutes-accessories' => self::singleTag('30-minutes-accessories', '30 Minutes Accessories', 'mk:line:30mm_accessories'),
        ];
    }

    /**
     * @return array<string, ShelfMeta>
     */
    private static function otherModelKits(): array
    {
        return [
            'pokemon' => self::singleTag('pokemon', 'Pokémon', 'mk:grade:pokemon'),
            'kotobukiya' => self::singleTag('kotobukiya', 'Kotobukiya', 'mk:grade:kotobukiya'),
            'moderoid' => self::singleTag('moderoid', 'MODEROID', 'mk:line:moderoid'),
            'keroro' => self::singleTag('keroro', 'Keroro', 'mk:grade:keroro'),
            'snaa' => self::singleTag('snaa', 'SNAA', StorefrontTag::MK_LINE_SNAA),
            'one-piece' => self::singleTag('one-piece', 'One Piece', StorefrontTag::MK_LINE_ONE_PIECE),
            'eureka-seven' => self::singleTag('eureka-seven', 'Eureka Seven', StorefrontTag::MK_LINE_EUREKA_SEVEN),
            'mechatrowego' => self::singleTag('mechatrowego', 'MechatroWeGo', StorefrontTag::MK_LINE_MECHATROWEGO),
            'plamax' => self::singleTag('plamax', 'PLAMAX', StorefrontTag::MK_LINE_PLAMAX),
            'evangelion' => self::singleTag('evangelion', 'Evangelion', StorefrontTag::MK_LINE_EVANGELION),
        ];
    }

    /**
     * Subline shelf: match canonical subline tag or legacy type-only grade tag.
     *
     * @return ShelfMeta
     */
    private static function sublineShelf(string $handle, string $title, string $slug): array
    {
        return self::orTags($handle, $title, [
            'mk:subline:'.$slug,
            'mk:grade:'.$slug,
        ]);
    }

    /**
     * @param  list<string>  $tags
     * @return ShelfMeta
     */
    private static function orTags(string $handle, string $title, array $tags): array
    {
        if (count($tags) === 1) {
            return self::singleTag($handle, $title, $tags[0]);
        }

        return [
            'handle' => $handle,
            'title' => $title,
            'tags' => $tags,
            'disjunctive' => true,
        ];
    }

    /**
     * @return ShelfMeta
     */
    private static function singleTag(string $handle, string $title, string $tag): array
    {
        return [
            'handle' => $handle,
            'title' => $title,
            'tag' => $tag,
        ];
    }
}
