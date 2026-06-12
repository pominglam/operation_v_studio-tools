<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlamodPreorder;

$include = [
    'tier1_gundam' => [
        'Mobile Suit Gundam', 'Mobile Suit Gundam Unicorn / Narrative', 'Mobile Suit Gundam Wing',
        'Mobile Suit Gundam Zeta / ZZ', 'Mobile Suit Gundam SEED / Destiny / Astray',
        'Mobile Suit Gundam 00', 'Mobile Suit Gundam GQuuuuuuX', 'Mobile Suit Gundam: The Witch from Mercury',
        'Mobile Suit Victory Gundam', 'Gundam Build Fighters / Try', 'Mobile Suit Gundam: OVAs and Side Stories',
        'After War Gundam X', 'Turn A Gundam', 'Gundam Reconguista in G', 'Mobile Fighter G Gundam',
        'Mobile Suit Gundam: Char\'s Counterattack', 'Mobile Suit Gundam AGE',
        'Mobile Suit Gundam: Iron-Blooded Orphans', 'MOBILE SUIT GUNDAM HATHAWAY The Sorcery of Nymph Circe', 'Gundam Misc',
    ],
    'tier2_30min' => [
        '30 Minutes Missions (30MM)', '30 Minutes Sisters (30MS)', '30 Minutes Fantasy (30MF)', '30 Minutes Label',
    ],
    'tier2b_sd_category' => ['SD Cross Silhouette', 'SD G Generation', 'SD EX-Standard', 'SD BB'],
    'tier3_other' => [
        'ARMORED CORE', 'AMAIM Warrior at the Borderline', 'Sgt. Keroro', 'MACROSS',
        'SD Gundam Sangokuden Brave Battle Warriors', 'SD World Heroes', 'SD BB', 'PLANNOSAURUS',
        'Armored Trooper VOTOMs', 'Code Geass: Lelouch of the Rebellion', 'Super Robot Wars',
        'Ultraman', 'Doraemon', 'Pokémon',
        'Jurassic Park', 'Mobile Police Patlabor', 'Space Battleship Yamato', 'Vertex Force',
    ],
];

$excludeIp = [
    '86 EIGHTY-SIX', 'Accel World', 'Aura Battler Dunbine', 'Blue Archive', 'Bocchi the Rock!',
    'Brain Powerd', 'Choujuu Sentai Liveman', 'Cowboy Bebop', 'DAEMON X MACHINA', 'Date A Live',
    'DC Comics', 'Demon Slayer: Kimetsu no Yaiba', 'Detective Conan', 'Digimon',
    'Dragon Ball', 'Dragon Ball DAIMA', 'Dragon Ball GT', 'Dragon Ball Super', 'Dragon Ball Z',
    'Dragon Quest', 'Eureka Seven', 'Fate/ series', 'FRAME ARMS', 'Frame Arms Girl',
    'Full Metal Panic!', 'Getter Robo', 'Ghost in the Shell',
    'Jujutsu Kaisen', 'Love Live!', 'One Piece', 'Puella Magi Madoka Magica',
    'THE iDOLM@STER', 'Yu-Gi-Oh!', 'Star Wars', 'Neon Genesis Evangelion',
];

$includeFlat = [];
foreach ($include as $group => $names) {
    foreach ($names as $n) {
        $includeFlat[$n] = $group;
    }
}
$excludeSet = array_fill_keys($excludeIp, true);

$bandaiBySeries = PlamodPreorder::query()
    ->active()
    ->where('manufacturer', 'BANDAI HOBBY')
    ->selectRaw('COALESCE(NULLIF(TRIM(series), ""), "(blank)") as s, COUNT(*) as c')
    ->groupBy('s')
    ->orderByDesc('c')
    ->pluck('c', 's')
    ->all();

echo "=== MODEL: Manufacturer gate + IP inclusion ===\n";
echo "Manufacturer scrape scope: BANDAI HOBBY only (mfr id=1)\n";
echo "Hub CSV: all manufacturers (separate; not part of mfr series loop)\n";
echo "IP filter: curated INCLUDE list on SERIES tab (+ Tier 2b CATEGORY)\n\n";

echo '--- INCLUDE IPs ('.count($includeFlat)." series names + 4 category lines) ---\n";
foreach ($include as $group => $names) {
    echo "\n[{$group}]\n";
    foreach ($names as $name) {
        $cnt = $bandaiBySeries[$name] ?? 0;
        echo sprintf("  + %s | bandai_db=%d\n", $name, $cnt);
    }
}

echo "\n--- EXCLUDE IPs (".count($excludeIp)." — on Bandai page but not in shop focus) ---\n";
foreach ($excludeIp as $name) {
    $cnt = $bandaiBySeries[$name] ?? 0;
    $flag = $cnt > 0 ? "bandai_db={$cnt} (would drop existing if never re-seen)" : 'bandai_db=0';
    echo sprintf("  - %s | %s\n", $name, $flag);
}

$uncategorized = [];
foreach ($bandaiBySeries as $series => $cnt) {
    if ($series === '(blank)' || $series === 'No Series') {
        continue;
    }
    if (isset($includeFlat[$series]) || isset($excludeSet[$series])) {
        continue;
    }
    $uncategorized[$series] = $cnt;
}
arsort($uncategorized);

echo "\n--- BANDAI series in DB not yet in include OR exclude (decide) ---\n";
echo 'count='.count($uncategorized)."\n";
foreach ($uncategorized as $series => $cnt) {
    echo sprintf("  ? %s | bandai_db=%d\n", $series, $cnt);
}

echo "\n--- Meta (not IP series) ---\n";
echo '  (blank) series: '.($bandaiBySeries['(blank)'] ?? 0)." bandai rows\n";
echo '  No Series: '.($bandaiBySeries['No Series'] ?? 0)." bandai rows (accessories/parts)\n";

$incTotal = 0;
foreach ($includeFlat as $n => $_) {
    $incTotal += $bandaiBySeries[$n] ?? 0;
}
$excTotal = 0;
foreach ($excludeIp as $n) {
    $excTotal += $bandaiBySeries[$n] ?? 0;
}
echo "\n--- Coverage ---\n";
echo 'Bandai active rows in INCLUDE IPs: '.$incTotal.' / 375'."\n";
echo 'Bandai active rows in EXCLUDE IPs: '.$excTotal.' / 375'."\n";
echo 'SERIES passes to run: '.count($includeFlat).' (+ '.count($include['tier2b_sd_category'])." category for Tier 2b)\n";
