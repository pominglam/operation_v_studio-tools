<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlamodPreorder;

$h = file_get_contents(__DIR__.'/storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html');

// Pull list (Tiers 1, 2, 3, 2b category add-on noted separately)
$pullSeries = [
    // Tier 1
    'Mobile Suit Gundam', 'Mobile Suit Gundam Unicorn / Narrative', 'Mobile Suit Gundam Wing',
    'Mobile Suit Gundam Zeta / ZZ', 'Mobile Suit Gundam SEED / Destiny / Astray',
    'Mobile Suit Gundam 00', 'Mobile Suit Gundam GQuuuuuuX', 'Mobile Suit Gundam: The Witch from Mercury',
    'Mobile Suit Victory Gundam', 'Gundam Build Fighters / Try', 'Mobile Suit Gundam: OVAs and Side Stories',
    'After War Gundam X', 'Turn A Gundam', 'Gundam Reconguista in G', 'Mobile Fighter G Gundam',
    'Mobile Suit Gundam: Char\'s Counterattack', 'Mobile Suit Gundam AGE',
    'Mobile Suit Gundam: Iron-Blooded Orphans', 'MOBILE SUIT GUNDAM HATHAWAY The Sorcery of Nymph Circe', 'Gundam Misc',
    // Tier 2
    '30 Minutes Missions (30MM)', '30 Minutes Sisters (30MS)', '30 Minutes Fantasy (30MF)', '30 Minutes Label',
    // Tier 3
    'ARMORED CORE', 'AMAIM Warrior at the Borderline', 'Sgt. Keroro', 'MACROSS',
    'SD Gundam Sangokuden Brave Battle Warriors', 'SD World Heroes', 'SD BB', 'PLANNOSAURUS',
    'Armored Trooper VOTOMs', 'Code Geass: Lelouch of the Rebellion', 'Super Robot Wars',
    'Ultraman', 'Doraemon', 'Pokémon',
];

$pullSet = array_fill_keys($pullSeries, true);

// Explicit skip from prior analysis (screenshot + hub-only)
$explicitSkip = [
    '86 EIGHTY-SIX', 'Accel World', 'Aura Battler Dunbine', 'Blue Archive', 'Bocchi the Rock!',
    'Brain Powerd', 'Choujuu Sentai Liveman', 'Cowboy Bebop', 'DAEMON X MACHINA', 'Date A Live',
    'DC Comics', 'Demon Slayer: Kimetsu no Yaiba', 'Detective Conan', 'Digimon',
    'Dragon Ball', 'Dragon Ball DAIMA', 'Dragon Ball GT', 'Dragon Ball Super', 'Dragon Ball Z',
    'Dragon Quest', 'Eureka Seven', 'Fate/ series', 'FRAME ARMS', 'Frame Arms Girl',
    'Full Metal Panic!', 'Getter Robo', 'Ghost in the Shell',
    'Jujutsu Kaisen', 'Love Live!', 'One Piece', 'Puella Magi Madoka Magica',
    'THE iDOLM@STER', 'Yu-Gi-Oh!', 'No Series', 'Star Wars', 'Neon Genesis Evangelion',
];

// Series names appearing in product rows on manufacturer preorder page (current single-filter view)
preg_match_all('/title="([^"]+)" href="\/retailer\/search\?series=([^"]+)"/', $h, $titleLinks, PREG_SET_ORDER);
$fromProductTitles = [];
foreach ($titleLinks as $m) {
    $fromProductTitles[html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)] = urldecode($m[2]);
}

// Broader: any series= in HTML
preg_match_all('/series=([^"&]+)/', $h, $rawLinks);
$fromAnyLink = [];
foreach ($rawLinks[1] as $raw) {
    $name = urldecode($raw);
    $fromAnyLink[$name] = true;
}

// DB: all distinct series on Bandai HOBBY active rows
$dbBandaiSeries = PlamodPreorder::query()
    ->active()
    ->where('manufacturer', 'BANDAI HOBBY')
    ->selectRaw('COALESCE(NULLIF(TRIM(series), ""), "(blank)") as s, COUNT(*) as c')
    ->groupBy('s')
    ->orderBy('s')
    ->pluck('c', 's')
    ->all();

// DB: all distinct series across all active preorders (hub includes non-bandai)
$dbAllSeries = PlamodPreorder::query()
    ->active()
    ->selectRaw('COALESCE(NULLIF(TRIM(series), ""), "(blank)") as s, COUNT(*) as c')
    ->groupBy('s')
    ->orderByDesc('c')
    ->pluck('c', 's')
    ->all();

echo "=== COUNTS ===\n";
echo 'Pull list (Tiers 1+2+3): '.count($pullSeries)."\n";
echo 'Explicit skip list: '.count($explicitSkip)."\n";
echo 'Series in product title links on mfr HTML: '.count($fromProductTitles)."\n";
echo 'Series in any series= URL on mfr HTML: '.count($fromAnyLink)."\n";
echo 'Distinct series on BANDAI HOBBY (DB): '.count($dbBandaiSeries)."\n";
echo 'Distinct series all active (DB): '.count($dbAllSeries)."\n\n";

// Union of known series names from DB + screenshot skip + pull
$screenshotVisible = [
    '30 Minutes Fantasy (30MF)', '30 Minutes Label', '30 Minutes Missions (30MM)', '30 Minutes Sisters (30MS)',
    '86 EIGHTY-SIX', 'Accel World', 'After War Gundam X', 'AMAIM Warrior at the Borderline', 'ARMORED CORE',
    'Armored Trooper VOTOMs', 'Aura Battler Dunbine', 'Blue Archive', 'Bocchi the Rock!', 'Brain Powerd',
    'Choujuu Sentai Liveman', 'Code Geass: Lelouch of the Rebellion', 'Cowboy Bebop', 'DAEMON X MACHINA',
    'Date A Live', 'DC Comics', 'Demon Slayer: Kimetsu no Yaiba', 'Detective Conan', 'Digimon', 'Doraemon',
    'Dragon Ball', 'Dragon Ball DAIMA', 'Dragon Ball GT', 'Dragon Ball Super', 'Dragon Ball Z', 'Dragon Quest',
    'Eureka Seven', 'Fate/ series', 'FRAME ARMS', 'Frame Arms Girl', 'Full Metal Panic!', 'Getter Robo',
    'Ghost in the Shell',
];

$universe = [];
foreach (array_keys($dbBandaiSeries) as $s) {
    if ($s !== '(blank)') {
        $universe[$s] = 'db_bandai';
    }
}
foreach ($screenshotVisible as $s) {
    $universe[$s] = ($universe[$s] ?? 'screenshot');
}
foreach (array_keys($fromProductTitles) as $s) {
    $universe[$s] = ($universe[$s] ?? 'html_product');
}
foreach ($pullSeries as $s) {
    $universe[$s] = 'pull';
}
foreach ($explicitSkip as $s) {
    $universe[$s] = 'skip';
}

echo '=== CLASSIFY known universe ('.count($universe).' names) ==='."\n";
$pull = $skip = $other = $unclassified = [];
foreach ($universe as $name => $src) {
    if (isset($pullSet[$name])) {
        $pull[] = $name;
    } elseif (in_array($name, $explicitSkip, true)) {
        $skip[] = $name;
    } elseif ($src === 'skip') {
        $skip[] = $name;
    } else {
        $other[] = $name;
    }
}
sort($pull);
sort($skip);
sort($other);

echo 'In pull list: '.count($pull)."\n";
echo 'In skip list: '.count(array_unique($skip))."\n";
echo 'Known but neither pull nor skip: '.count($other)."\n\n";

if ($other !== []) {
    echo "--- OTHER (would pull if we do \"everything except hub-only\") ---\n";
    foreach ($other as $name) {
        $cnt = $dbBandaiSeries[$name] ?? 0;
        echo "  {$name} | db_bandai={$cnt}\n";
    }
    echo "\n";
}

echo '--- EXPLICIT SKIP ('.count(array_unique($skip)).") ---\n";
foreach (array_unique($skip) as $name) {
    $cnt = $dbBandaiSeries[$name] ?? 0;
    $badge = $cnt > 0 ? "db_bandai={$cnt}" : 'db_bandai=0';
    echo "  {$name} | {$badge}\n";
}

// Hub-only: series that exist in all DB but not bandai
echo "\n=== Series only on NON-Bandai hub rows (don't use mfr SERIES pull) ===\n";
$hubOnly = [];
foreach ($dbAllSeries as $series => $cnt) {
    if ($series === '(blank)' || $series === 'No Series') {
        continue;
    }
    $bandaiCnt = $dbBandaiSeries[$series] ?? 0;
    if ($bandaiCnt === 0 && $cnt > 0) {
        $hubOnly[$series] = $cnt;
    }
}
echo 'Count: '.count($hubOnly)."\n";
foreach ($hubOnly as $series => $cnt) {
    echo "  {$series}: {$cnt} rows (hub/other mfr)\n";
}

// Tier 2b category (not series)
echo "\n=== Tier 2b (CATEGORY lines, not SERIES tab) ===\n";
foreach (['SD Cross Silhouette', 'SD G Generation', 'SD EX-Standard', 'SD BB', 'Pokemon'] as $cat) {
    $cnt = PlamodPreorder::query()->active()->where('manufacturer', 'BANDAI HOBBY')->where('category', $cat)->count();
    echo "  {$cat}: db={$cnt}\n";
}

// Estimate total SERIES tab size: Plamod shows scrollable list; use DB bandai + screenshot + typical Bandai catalog
echo "\n=== REALISTIC SKIP vs PULL MATH ===\n";
$pullCount = count($pullSeries);
$skipUnique = count(array_unique($skip));
$otherCount = count($other);
$estimatedSeriesTabTotal = count($universe); // lower bound from known names
echo "Conservative known series names: {$estimatedSeriesTabTotal}\n";
echo "Pull (Tiers 1-3): {$pullCount}\n";
echo "Explicit skip: {$skipUnique}\n";
echo "Gap (known names not in pull or skip): {$otherCount}\n";
echo 'If SERIES tab has ~'.($estimatedSeriesTabTotal + 50).' entries (scroll below Ghost in Shell), skipped fraction ≈ '.
    round(100 * $skipUnique / max(1, $estimatedSeriesTabTotal + 50), 1)."%\n";
echo 'If we pull pull+other+skip bandai-relevant: ≈ '.($pullCount + $skipUnique + $otherCount)." series passes (+ Tier 2b category)\n";
