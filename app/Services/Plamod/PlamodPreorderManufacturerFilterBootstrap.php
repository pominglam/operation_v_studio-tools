<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Enums\PlamodPreorderManufacturerFilterDecision;

final class PlamodPreorderManufacturerFilterBootstrap
{
    /** @var array<int, string> */
    public const array DEFAULT_INCLUDE_SERIES = [
        'Mobile Suit Gundam',
        'Mobile Suit Gundam Unicorn / Narrative',
        'Mobile Suit Gundam Wing',
        'Mobile Suit Gundam Zeta / ZZ',
        'Mobile Suit Gundam SEED / Destiny / Astray',
        'Mobile Suit Gundam 00',
        'Mobile Suit Gundam GQuuuuuuX',
        'Mobile Suit Gundam: The Witch from Mercury',
        'Mobile Suit Victory Gundam',
        'Gundam Build Fighters / Try',
        'Mobile Suit Gundam: OVAs and Side Stories',
        'After War Gundam X',
        'Turn A Gundam',
        'Gundam Reconguista in G',
        'Mobile Fighter G Gundam',
        'Mobile Suit Gundam: Char\'s Counterattack',
        'Mobile Suit Gundam AGE',
        'Mobile Suit Gundam: Iron-Blooded Orphans',
        'MOBILE SUIT GUNDAM HATHAWAY The Sorcery of Nymph Circe',
        'Gundam Misc',
        '30 Minutes Missions (30MM)',
        '30 Minutes Sisters (30MS)',
        '30 Minutes Fantasy (30MF)',
        '30 Minutes Label',
        'ARMORED CORE',
        'AMAIM Warrior at the Borderline',
        'Sgt. Keroro',
        'MACROSS',
        'SD Gundam Sangokuden Brave Battle Warriors',
        'SD World Heroes',
        'SD BB',
        'PLANNOSAURUS',
        'Armored Trooper VOTOMs',
        'Code Geass: Lelouch of the Rebellion',
        'Super Robot Wars',
        'Ultraman',
        'Doraemon',
        'Pokémon',
        'Jurassic Park',
        'Mobile Police Patlabor',
        'Space Battleship Yamato',
        'Vertex Force',
    ];

    /** @var array<int, string> */
    public const array DEFAULT_INCLUDE_CATEGORY_LINES = [
        'SD Cross Silhouette',
        'SD G Generation',
        'SD EX-Standard',
        'SD BB',
    ];

    /** @var array<int, string> */
    public const array DEFAULT_EXCLUDE_SERIES = [
        '86 EIGHTY-SIX',
        'Accel World',
        'Aura Battler Dunbine',
        'Blue Archive',
        'Bocchi the Rock!',
        'Brain Powerd',
        'Choujuu Sentai Liveman',
        'Cowboy Bebop',
        'DAEMON X MACHINA',
        'Date A Live',
        'DC Comics',
        'Demon Slayer: Kimetsu no Yaiba',
        'Detective Conan',
        'Digimon',
        'Dragon Ball',
        'Dragon Ball DAIMA',
        'Dragon Ball GT',
        'Dragon Ball Super',
        'Dragon Ball Z',
        'Dragon Quest',
        'Eureka Seven',
        'Fate/ series',
        'FRAME ARMS',
        'Frame Arms Girl',
        'Full Metal Panic!',
        'Getter Robo',
        'Ghost in the Shell',
        'Jujutsu Kaisen',
        'Love Live!',
        'One Piece',
        'Puella Magi Madoka Magica',
        'THE iDOLM@STER',
        'Yu-Gi-Oh!',
        'Star Wars',
        'Neon Genesis Evangelion',
    ];

    public function defaultDecisionFor(string $filterType, string $name): PlamodPreorderManufacturerFilterDecision
    {
        if ($filterType === 'category_line') {
            if (in_array($name, self::DEFAULT_INCLUDE_CATEGORY_LINES, true)) {
                return PlamodPreorderManufacturerFilterDecision::Include;
            }

            return PlamodPreorderManufacturerFilterDecision::Undecided;
        }

        if (in_array($name, self::DEFAULT_INCLUDE_SERIES, true)) {
            return PlamodPreorderManufacturerFilterDecision::Include;
        }

        if (in_array($name, self::DEFAULT_EXCLUDE_SERIES, true)) {
            return PlamodPreorderManufacturerFilterDecision::Exclude;
        }

        return PlamodPreorderManufacturerFilterDecision::Undecided;
    }
}
