<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Support\Products\Storefront\StorefrontTag;

/**
 * Canonical model-kit series: ERP display name ↔ storefront mk:series tag slug ↔ title signals.
 *
 * Source of truth for series audit + inference. Keep aligned with ModelKitShelfCatalog filters.
 */
final class ModelKitSeriesCatalog
{
    /**
     * @return array<string, string> tag slug => canonical ERP `products.series` value
     */
    public static function erpSeriesByTagSlug(): array
    {
        return [
            'mobile_suit_gundam' => 'Mobile Suit Gundam',
            'zeta_gundam' => 'Zeta Gundam',
            'gundam_zz' => 'Gundam ZZ',
            'char_s_counterattack' => "Char's Counterattack",
            'gundam_0080__war_in_the_pocket' => 'Gundam 0080: War in the Pocket',
            'gundam_0083__stardust_memory' => 'Gundam 0083: Stardust Memory',
            'the_08th_ms_team' => 'The 08th MS Team',
            'gundam__the_origin' => 'Gundam: The Origin',
            'gundam_thunderbolt' => 'Gundam Thunderbolt',
            'gundam_narrative' => 'Gundam Narrative',
            'gundam_sentinel' => 'Gundam Sentinel',
            'gundam_unicorn' => 'Gundam Unicorn',
            'gundam_f91' => 'Gundam F91',
            'gundam_hathaway' => "Gundam Hathaway's Flash",
            'hathaway' => "Gundam Hathaway's Flash",
            'gundam_reconguista_in_g' => 'Gundam Reconguista in G',
            'gundam__requiem_for_vengeance' => 'Gundam: Requiem for Vengeance',
            'nextuc' => 'Mobile Suit Gundam Narrative (UC)',
            'advance_of_zeta' => 'Advance of Zeta',
            'titanomachia' => 'Gundam Titanomachia',
            'g_gundam' => 'G Gundam',
            'gundam_wing' => 'Gundam Wing',
            'gundam_wing__endless_waltz' => 'Gundam Wing: Endless Waltz',
            'gundam_seed' => 'Gundam SEED',
            'gundam_seed_destiny' => 'Gundam Seed Destiny',
            'gundam_seed_freedom' => 'Gundam Seed Freedom',
            'gundam_seed_astray' => 'Gundam Seed Astray',
            'gundam_seed_stargazer' => 'Gundam Seed Stargazer',
            'gundam_00' => 'Gundam 00',
            'gundam_age' => 'Gundam Age',
            'gundam_build_fighters' => 'Gundam Build Fighters',
            'iron_blooded_orphans' => 'Iron-Blooded Orphans',
            'gundam_build_divers' => 'Gundam Build Divers',
            'gundam_build_divers_re_rise' => 'Gundam Build Divers Re:Rise',
            'gundam_build_metaverse' => 'Gundam Build Metaverse',
            'buildmetaverse' => 'Gundam Build Metaverse',
            'gundam_breaker_battlogue' => 'Gundam Breaker Battlogue',
            'the_witch_from_mercury' => 'The Witch From Mercury',
            'mobile_suit_gundam_gquuuuuux' => 'Mobile Suit Gundam GQuuuuuuX',
            'gundam_x' => 'After War Gundam X',
            'patlabor' => 'Patlabor',
            'macross_delta' => 'Macross Delta',
            'armored_trooper_votoms' => 'Armored Trooper Votoms',
            'mazinger' => 'Mazinger',
            'getter_robo' => 'Getter Robo',
            'kotetsu_jeeg' => 'Kotetsu Jeeg',
            'super_robot_wars' => 'Super Robot Wars',
            'armored_core' => 'Armored Core VI: Fires Of Rubicon',
            'doraemon' => 'Doraemon',
            'sakura_wars' => 'Sakura Wars',
            'linebarrels_of_iron' => 'Linebarrels of Iron',
            'eureka_seven' => 'Eureka Seven',
            'evangelion' => 'Evangelion',
        ];
    }

    /**
     * Ordered inference rules — first match wins. Patterns are case-insensitive regex unless plain string (contains).
     *
     * @return list<array{ruleId: string, tagSlug: string, confidence: string, patterns: list<string>}>
     */
    public static function inferenceRules(): array
    {
        return [
            ['ruleId' => 'evangelion', 'tagSlug' => 'evangelion', 'confidence' => 'high', 'patterns' => self::evangelionTitleSignals()],
            ['ruleId' => 'gquuuuuux', 'tagSlug' => 'mobile_suit_gundam_gquuuuuux', 'confidence' => 'high', 'patterns' => ['GQUUUUUUX', 'GQUUUUUUUX']],
            ['ruleId' => 'witch_from_mercury', 'tagSlug' => 'the_witch_from_mercury', 'confidence' => 'high', 'patterns' => ['WITCH FROM MERCURY', 'THE WFM', 'GUNDAM THE WITCH FROM MERCURY']],
            ['ruleId' => 'seed_freedom', 'tagSlug' => 'gundam_seed_freedom', 'confidence' => 'high', 'patterns' => ['SEED FREEDOM']],
            ['ruleId' => 'seed_destiny', 'tagSlug' => 'gundam_seed_destiny', 'confidence' => 'high', 'patterns' => ['SEED DESTINY']],
            ['ruleId' => 'seed_astray', 'tagSlug' => 'gundam_seed_astray', 'confidence' => 'high', 'patterns' => ['SEED ASTRAY', 'ASTRAY']],
            ['ruleId' => 'seed_stargazer', 'tagSlug' => 'gundam_seed_stargazer', 'confidence' => 'high', 'patterns' => ['STARGAZER']],
            ['ruleId' => 'seed', 'tagSlug' => 'gundam_seed', 'confidence' => 'high', 'patterns' => ['\b(?:GUNDAM )?SEED\b']],
            ['ruleId' => 'ibo', 'tagSlug' => 'iron_blooded_orphans', 'confidence' => 'high', 'patterns' => ['IRON-BLOODED ORPHANS', 'IRON BLOODED ORPHANS', '\bIBO\b', 'TEKKEN', 'BARBATOS', 'GUNDAM AGEIRTR']],
            ['ruleId' => 'gundam_00', 'tagSlug' => 'gundam_00', 'confidence' => 'high', 'patterns' => [
                '\bGUNDAM 00\b', '\b00 RAISER\b', '\b00 QAN\[T\]', '\b00 SKY\b',
                '\b(?:EXIA|DYNAMES|KYRIOS|VIRTUE|ARIOS|SERAVEE|SERAPHIM|CHERUDIM|SUSANOO|GARBACHT|GN ARCH\b)\b',
                '\bGN-000\b', '\bGN-001\b', '\bGN-002\b', '\bGN-003\b', '\bGN-005\b', '\bGN-006\b', '\bGN-007\b', '\bGN-008\b', '\bGN-009\b',
            ]],
            ['ruleId' => 'wing_ew', 'tagSlug' => 'gundam_wing__endless_waltz', 'confidence' => 'high', 'patterns' => [
                'ENDLESS WALTZ', '\bZERO EW\b', '\bWING GUNDAM ZERO\b', '\bXXXG-00W0\b', '\bXXXG-00W\b',
            ]],
            ['ruleId' => 'wing', 'tagSlug' => 'gundam_wing', 'confidence' => 'high', 'patterns' => ['\bGUNDAM WING\b', '\bWING GUNDAM\b', '\bXXXG-0', '\bOZ-00\b']],
            ['ruleId' => 'build_divers_rerise', 'tagSlug' => 'gundam_build_divers_re_rise', 'confidence' => 'high', 'patterns' => ['BUILD DIVERS RE:RISE', 'BUILD DIVERS RERISE', '\bHGBD:R\b']],
            ['ruleId' => 'build_metaverse', 'tagSlug' => 'gundam_build_metaverse', 'confidence' => 'high', 'patterns' => ['BUILD METAVERSE', 'G BUILD METAVERSE']],
            ['ruleId' => 'build_divers', 'tagSlug' => 'gundam_build_divers', 'confidence' => 'high', 'patterns' => ['BUILD DIVERS']],
            ['ruleId' => 'build_fighters', 'tagSlug' => 'gundam_build_fighters', 'confidence' => 'high', 'patterns' => ['BUILD FIGHTERS', 'HGBF', 'GUNDAM BUILD FIGHTERS']],
            ['ruleId' => 'g_gundam', 'tagSlug' => 'g_gundam', 'confidence' => 'high', 'patterns' => ['\bG GUNDAM\b', 'GUNDAM FIGHT', 'SHINING GUNDAM', 'GOD GUNDAM']],
            ['ruleId' => 'gundam_age', 'tagSlug' => 'gundam_age', 'confidence' => 'high', 'patterns' => ['\bGUNDAM AGE\b', '\bAGE-', '\bAGE ']],
            ['ruleId' => 'requiem', 'tagSlug' => 'gundam__requiem_for_vengeance', 'confidence' => 'high', 'patterns' => ['REQUIEM FOR VENGEANCE']],
            ['ruleId' => 'hathaway', 'tagSlug' => 'gundam_hathaway', 'confidence' => 'high', 'patterns' => ['HATHAWAY', 'XI GUNDAM', 'PENELOPE', '\bMESSER\b']],
            ['ruleId' => 'unicorn', 'tagSlug' => 'gundam_unicorn', 'confidence' => 'high', 'patterns' => ['UNICORN GUNDAM', '\bRX-0\b', 'GUNDAM UC', '\bUC\b GUNDAM']],
            ['ruleId' => 'narrative', 'tagSlug' => 'gundam_narrative', 'confidence' => 'high', 'patterns' => ['GUNDAM NARRATIVE', 'NARRATIVE GUNDAM']],
            ['ruleId' => 'thunderbolt', 'tagSlug' => 'gundam_thunderbolt', 'confidence' => 'high', 'patterns' => ['THUNDERBOLT', 'FULL ARMOR GUNDAM']],
            ['ruleId' => 'sentinel', 'tagSlug' => 'gundam_sentinel', 'confidence' => 'high', 'patterns' => ['GUNDAM SENTINEL', 'SENTINEL']],
            ['ruleId' => 'origin', 'tagSlug' => 'gundam__the_origin', 'confidence' => 'high', 'patterns' => ['THE ORIGIN', 'GUNDAM THE ORIGIN']],
            ['ruleId' => '08th_ms', 'tagSlug' => 'the_08th_ms_team', 'confidence' => 'high', 'patterns' => ['08TH MS TEAM', '8TH MS TEAM']],
            ['ruleId' => '0083', 'tagSlug' => 'gundam_0083__stardust_memory', 'confidence' => 'high', 'patterns' => ['0083', 'STARDUST MEMORY', 'GP0[1-4]', 'GERBERA']],
            ['ruleId' => '0080', 'tagSlug' => 'gundam_0080__war_in_the_pocket', 'confidence' => 'high', 'patterns' => ['0080', 'WAR IN THE POCKET', 'GM SNIPER', 'GM COMMAND', 'ZAKU II FZ']],
            ['ruleId' => 'f91', 'tagSlug' => 'gundam_f91', 'confidence' => 'high', 'patterns' => ['\bF91\b', 'GUNDAM F91']],
            ['ruleId' => 'cca', 'tagSlug' => 'char_s_counterattack', 'confidence' => 'high', 'patterns' => ["CHAR'S COUNTERATTACK", 'NU GUNDAM', 'SAZABI', 'HI-NU', 'RX-93']],
            ['ruleId' => 'zz', 'tagSlug' => 'gundam_zz', 'confidence' => 'high', 'patterns' => ['GUNDAM ZZ', '\bQUBELEY\b', 'HAMBRABI', 'BAWOO', 'ZZ GUNDAM']],
            ['ruleId' => 'zeta', 'tagSlug' => 'zeta_gundam', 'confidence' => 'high', 'patterns' => ['ZETA GUNDAM', '\bMSZ-006\b', '\bMSZ-010\b', 'HYAKU SHIKI', 'GUNDAM MK-II', 'GUNDAM MK II', '\bRX-178\b']],
            ['ruleId' => 'reconguista', 'tagSlug' => 'gundam_reconguista_in_g', 'confidence' => 'high', 'patterns' => ['RECONGUITA', 'G-SELF', 'MONTERO']],
            ['ruleId' => 'gundam_x', 'tagSlug' => 'gundam_x', 'confidence' => 'high', 'patterns' => ['GUNDAM X', 'GUNDAM DOUBLE X', 'DX GUNDAM']],
            ['ruleId' => '0079', 'tagSlug' => 'mobile_suit_gundam', 'confidence' => 'medium', 'patterns' => ['\bRX-78', '\bMS-06', '\bMS-07', '\bMS-09', '\bMS-14', '\bMS-18', '\bGELGOOG\b', 'GUNCANNON', 'GUNTANK', 'GUNDAM GROUND TYPE']],
            ['ruleId' => 'patlabor', 'tagSlug' => 'patlabor', 'confidence' => 'high', 'patterns' => ['PATLABOR', 'INGRAM', 'AV-98']],
            ['ruleId' => 'macross', 'tagSlug' => 'macross_delta', 'confidence' => 'high', 'patterns' => ['\bMACROSS\b', '\bVF-31\b']],
            ['ruleId' => 'votoms', 'tagSlug' => 'armored_trooper_votoms', 'confidence' => 'high', 'patterns' => ['VOTOMS', 'SCOPE DOG', 'BRATHAT']],
            ['ruleId' => 'mazinger', 'tagSlug' => 'mazinger', 'confidence' => 'high', 'patterns' => ['MAZINGER', 'GREAT MAZINGER', 'GRENDIZER']],
            ['ruleId' => 'getter', 'tagSlug' => 'getter_robo', 'confidence' => 'high', 'patterns' => ['GETTER ROBO', 'GETTER DRAGON', 'SHIN GETTER']],
            ['ruleId' => 'jeeg', 'tagSlug' => 'kotetsu_jeeg', 'confidence' => 'high', 'patterns' => ['KOTETSU JEEG', 'JEEG']],
            ['ruleId' => 'srw', 'tagSlug' => 'super_robot_wars', 'confidence' => 'high', 'patterns' => ['SUPER ROBOT WARS', 'SRW']],
            ['ruleId' => 'armored_core', 'tagSlug' => 'armored_core', 'confidence' => 'high', 'patterns' => ['ARMORED CORE', 'NACHTREIHER', 'IB-07']],
            ['ruleId' => 'doraemon', 'tagSlug' => 'doraemon', 'confidence' => 'high', 'patterns' => ['DORAEMON']],
            ['ruleId' => 'sakura_wars', 'tagSlug' => 'sakura_wars', 'confidence' => 'high', 'patterns' => ['SAKURA WARS']],
            ['ruleId' => 'linebarrels', 'tagSlug' => 'linebarrels_of_iron', 'confidence' => 'high', 'patterns' => ['LINEBARRELS']],
            ['ruleId' => 'eureka', 'tagSlug' => 'eureka_seven', 'confidence' => 'high', 'patterns' => ['EUREKA SEVEN', 'EUREKA 7']],
        ];
    }

    /**
     * HG (and similar) subline → series when title lacks explicit series (medium confidence).
     *
     * @return array<string, array{tagSlug: string, confidence: string}>
     */
    public static function sublineSeriesHints(): array
    {
        return [
            'hgce' => ['tagSlug' => 'gundam_seed', 'confidence' => 'medium'],
            'hgac' => ['tagSlug' => 'gundam_wing', 'confidence' => 'medium'],
            'hgibo' => ['tagSlug' => 'iron_blooded_orphans', 'confidence' => 'medium'],
            'hgbf' => ['tagSlug' => 'gundam_build_fighters', 'confidence' => 'high'],
            'hgbd' => ['tagSlug' => 'gundam_build_divers', 'confidence' => 'high'],
            'hgbd:r' => ['tagSlug' => 'gundam_build_divers_re_rise', 'confidence' => 'high'],
        ];
    }

    /**
     * Character / title signals that are Evangelion even when the word Evangelion is absent.
     *
     * @return list<string>
     */
    public static function evangelionTitleSignals(): array
    {
        return [
            'EVANGELION',
            'SHIKINAMI',
            'AYANAMI',
            'MAKINAMI',
            'ASUKA LANGLEY',
            'SHINJI IKARI',
            'IKARI SHINJI',
            'KAWORU NAGISA',
            'NAGISA KAWORU',
            'PLUG SUIT',
        ];
    }

    public static function textLooksLikeEvangelion(string $text): bool
    {
        $upper = mb_strtoupper($text);
        if (preg_match('/\bEVA[\s-]?0?\d/', $upper) === 1) {
            return true;
        }

        foreach (self::evangelionTitleSignals() as $signal) {
            if (str_contains($upper, $signal)) {
                return true;
            }
        }

        return false;
    }

    public static function erpSeriesForTagSlug(string $tagSlug): ?string
    {
        return self::erpSeriesByTagSlug()[$tagSlug] ?? null;
    }

    public static function erpSeriesForFandomName(string $fandomSeries): ?string
    {
        $trimmed = trim($fandomSeries);
        if ($trimmed === '') {
            return null;
        }

        foreach (self::erpSeriesByTagSlug() as $erpSeries) {
            if (strcasecmp($erpSeries, $trimmed) === 0) {
                return $erpSeries;
            }
        }

        /** @var array<string, string> $aliases */
        $aliases = [
            'Mobile Suit Gundam: The Origin' => 'Gundam: The Origin',
            'Mobile Suit Gundam Thunderbolt' => 'Gundam Thunderbolt',
            'Mobile Suit Gundam Narrative' => 'Gundam Narrative',
            'Mobile Suit Gundam Unicorn' => 'Gundam Unicorn',
            'Mobile Suit Gundam F91' => 'Gundam F91',
            'After War Gundam X' => 'After War Gundam X',
            'Gundam Reconguista in G' => 'Gundam Reconguista in G',
            'Mobile Suit Gundam GQuuuuuuX' => 'Mobile Suit Gundam GQuuuuuuX',
            'Mobile Suit Gundam: The Witch from Mercury' => 'The Witch From Mercury',
            'Mobile Suit Gundam the Witch from Mercury' => 'The Witch From Mercury',
            'Gundam the Witch from Mercury' => 'The Witch From Mercury',
            'Gundam Build Fighters / Try' => 'Gundam Build Fighters',
            'Gundam Build Fighters' => 'Gundam Build Fighters',
            'Gundam Build Divers / Re:Divers' => 'Gundam Build Divers Re:Rise',
            'Mobile Suit Gundam: Iron-Blooded Orphans' => 'Iron-Blooded Orphans',
            'Mobile Suit Gundam Zeta' => 'Zeta Gundam',
            'Mobile Suit Gundam: OVAs and Side Stories' => 'Gundam 0080: War in the Pocket',
            'GUNDAM: Next Universal Century' => 'Mobile Suit Gundam Narrative (UC)',
            'MOBILE SUIT GUNDAM HATHAWAY The Sorcery of Nymph Circe' => "Gundam Hathaway's Flash",
            'Mobile Suit Gundam Unicorn / Narrative' => 'Gundam Unicorn',
            'Neon Genesis Evangelion' => 'Evangelion',
            'Macross' => 'Macross Delta',
            'Steel Jeeg' => 'Kotetsu Jeeg',
            'Mobile Suit Gundam SEED' => 'Gundam SEED',
            'Mobile Suit Gundam SEED Destiny' => 'Gundam Seed Destiny',
            'Mobile Suit Gundam SEED Freedom' => 'Gundam Seed Freedom',
            'Mobile Suit Gundam 00' => 'Gundam 00',
            'Mobile Suit Gundam Wing' => 'Gundam Wing',
            'Mobile Suit Gundam ZZ' => 'Gundam ZZ',
            'Mobile Suit Zeta Gundam' => 'Zeta Gundam',
            'Mobile Suit Gundam: Char\'s Counterattack' => 'Char\'s Counterattack',
            'Mobile Suit Gundam 0080: War in the Pocket' => 'Gundam 0080: War in the Pocket',
            'Mobile Suit Gundam 0083: Stardust Memory' => 'Gundam 0083: Stardust Memory',
            'Mobile Suit Gundam Hathaway\'s Flash' => 'Gundam Hathaway\'s Flash',
            'Mobile Suit Gundam: Requiem for Vengeance' => 'Gundam: Requiem for Vengeance',
            'G-Tekketsu' => 'Iron-Blooded Orphans',
            'Mobile Suit Gundam IRON-BLOODED ORPHANS' => 'Iron-Blooded Orphans',
            'Mobile Suit Gundam I' => 'Mobile Suit Gundam',
            'Mobile Suit Gundam Unicorn RE:0096' => 'Gundam Unicorn',
            'Mobile Suit Gundam Wing Endless Waltz' => 'Gundam Wing: Endless Waltz',
            'Mobile Suit Gundam Wing Endless Waltz: Glory of the Losers' => 'Gundam Wing: Endless Waltz',
            'Mobile Fighter G Gundam' => 'G Gundam',
            'Mobile Suit Gundam AGE' => 'Gundam Age',
            'Mobile Suit Gundam Hathaway' => "Gundam Hathaway's Flash",
            'Mobile Suit Gundam SEED Astray' => 'Gundam Seed Astray',
            'Mobile Suit Gundam SEED C.E. 73: STARGAZER' => 'Gundam Seed Stargazer',
            'Mobile Suit Gundam Wing' => 'Gundam Wing',
            'Mobile Suit Zeta Gundam' => 'Zeta Gundam',
            'Gundam Build Fighters Try' => 'Gundam Build Fighters Try',
            'Gundam Build Metaverse' => 'Gundam Build Metaverse',
        ];

        foreach ($aliases as $from => $to) {
            if (strcasecmp($from, $trimmed) === 0) {
                return $to;
            }
        }

        $slug = StorefrontTag::slugify($trimmed);

        return $slug !== null ? (self::erpSeriesForTagSlug($slug) ?? $trimmed) : $trimmed;
    }
}
