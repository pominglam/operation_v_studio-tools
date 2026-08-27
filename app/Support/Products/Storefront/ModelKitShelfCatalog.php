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
            'gunpla' => self::orTags('gunpla', 'Gunpla', ['mk:line:gunpla']),
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
            'gundam-universal-century' => self::sublineShelf('gundam-universal-century', 'Gundam Universal Century', 'hguc'),
            'gundam-seed' => self::orTags('gundam-seed', 'Gundam SEED', [
                'mk:series:gundam_seed',
                'mk:series:gundam_seed_destiny',
                'mk:series:gundam_seed_freedom',
                'mk:series:gundam_seed_astray',
            ]),
            'gundam-wing' => self::orTags('gundam-wing', 'Gundam Wing', [
                'mk:series:gundam_wing',
                'mk:series:gundam_wing_endless_waltz',
            ]),
            'gundam-00' => self::orTags('gundam-00', 'Gundam 00', ['mk:series:gundam_00']),
            'gundam-iron-blooded-orphans' => self::orTags('gundam-iron-blooded-orphans', 'Gundam Iron-Blooded Orphans', [
                'mk:series:iron_blooded_orphans',
            ]),
            'gundam-witch-from-mercury' => self::orTags('gundam-witch-from-mercury', 'Gundam The Witch from Mercury', [
                'mk:series:the_witch_from_mercury',
                'mk:series:mobile_suit_gundam_gquuuuuux',
            ]),
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
