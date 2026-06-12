<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlamodPreorder;

function isSparse(PlamodPreorder $row): bool
{
    return ! $row->price_preorder
        || trim((string) $row->product_name) === ''
        || trim((string) $row->product_name) === trim((string) $row->sku);
}

// Series visible in user screenshot (SERIES tab) + common Gundam series likely below fold.
$plamodSeries = [
    ['name' => '30 Minutes Fantasy (30MF)', 'preorder' => 26, 'other' => 21],
    ['name' => '30 Minutes Label', 'preorder' => 17, 'other' => 1],
    ['name' => '30 Minutes Missions (30MM)', 'preorder' => 70, 'other' => 22],
    ['name' => '30 Minutes Sisters (30MS)', 'preorder' => 67, 'other' => 23],
    ['name' => '86 EIGHTY-SIX', 'preorder' => 0, 'other' => 0],
    ['name' => 'Accel World', 'preorder' => 0, 'other' => 0],
    ['name' => 'After War Gundam X', 'preorder' => 4, 'other' => 10],
    ['name' => 'AMAIM Warrior at the Borderline', 'preorder' => 10, 'other' => 0],
    ['name' => 'ARMORED CORE', 'preorder' => 15, 'other' => 9],
    ['name' => 'Armored Trooper VOTOMs', 'preorder' => 0, 'other' => 2],
    ['name' => 'Aura Battler Dunbine', 'preorder' => 0, 'other' => 0],
    ['name' => 'Blue Archive', 'preorder' => 0, 'other' => 1],
    ['name' => 'Bocchi the Rock!', 'preorder' => 1, 'other' => 0],
    ['name' => 'Brain Powerd', 'preorder' => 0, 'other' => 0],
    ['name' => 'Choujuu Sentai Liveman', 'preorder' => 0, 'other' => 0],
    ['name' => 'Code Geass: Lelouch of the Rebellion', 'preorder' => 0, 'other' => 3],
    ['name' => 'Cowboy Bebop', 'preorder' => 0, 'other' => 0],
    ['name' => 'DAEMON X MACHINA', 'preorder' => 0, 'other' => 0],
    ['name' => 'Date A Live', 'preorder' => 0, 'other' => 0],
    ['name' => 'DC Comics', 'preorder' => 3, 'other' => 0],
    ['name' => 'Demon Slayer: Kimetsu no Yaiba', 'preorder' => 1, 'other' => 0],
    ['name' => 'Detective Conan', 'preorder' => 0, 'other' => 0],
    ['name' => 'Digimon', 'preorder' => 1, 'other' => 0],
    ['name' => 'Doraemon', 'preorder' => 2, 'other' => 1],
    ['name' => 'Dragon Ball', 'preorder' => 0, 'other' => 0],
    ['name' => 'Dragon Ball DAIMA', 'preorder' => 2, 'other' => 0],
    ['name' => 'Dragon Ball GT', 'preorder' => 0, 'other' => 0],
    ['name' => 'Dragon Ball Super', 'preorder' => 1, 'other' => 0],
    ['name' => 'Dragon Ball Z', 'preorder' => 1, 'other' => 0],
    ['name' => 'Dragon Quest', 'preorder' => 0, 'other' => 4],
    ['name' => 'Eureka Seven', 'preorder' => 2, 'other' => 0],
    ['name' => 'Fate/ series', 'preorder' => 0, 'other' => 0],
    ['name' => 'FRAME ARMS', 'preorder' => 0, 'other' => 0],
    ['name' => 'Frame Arms Girl', 'preorder' => 0, 'other' => 0],
    ['name' => 'Full Metal Panic!', 'preorder' => 0, 'other' => 0],
    ['name' => 'Getter Robo', 'preorder' => 2, 'other' => 0],
    ['name' => 'Ghost in the Shell', 'preorder' => 2, 'other' => 0],
];

// Likely below the fold on same SERIES tab (from prior DB top series + Gundam lines).
$belowFold = [
    'Gundam Build Fighters / Try',
    'Gundam Reconguista in G',
    'Gundam Misc',
    'Jujutsu Kaisen',
    'Love Live!',
    'MACROSS',
    'Mobile Fighter G Gundam',
    'Mobile Police Patlabor',
    'Mobile Suit Gundam',
    'Mobile Suit Gundam 00',
    'Mobile Suit Gundam AGE',
    'Mobile Suit Gundam GQuuuuuuX',
    'Mobile Suit Gundam SEED / Destiny / Astray',
    'Mobile Suit Gundam Unicorn / Narrative',
    'Mobile Suit Gundam Wing',
    'Mobile Suit Gundam Zeta / ZZ',
    'Mobile Suit Gundam: Iron-Blooded Orphans',
    'Mobile Suit Gundam: OVAs and Side Stories',
    'Mobile Suit Gundam: The Witch from Mercury',
    'Mobile Suit Gundam: Char\'s Counterattack',
    'Mobile Suit Victory Gundam',
    'MOBILE SUIT GUNDAM HATHAWAY The Sorcery of Nymph Circe',
    'Neon Genesis Evangelion',
    'No Series',
    'One Piece',
    'PLANNOSAURUS',
    'Pokémon',
    'Puella Magi Madoka Magica',
    'SD Gundam Sangokuden Brave Battle Warriors',
    'SD World Heroes',
    'Sgt. Keroro',
    'Star Wars',
    'Super Robot Wars',
    'THE iDOLM@STER',
    'Turn A Gundam',
    'Ultraman',
    'Yu-Gi-Oh!',
];

$skipSeries = [
    '86 EIGHTY-SIX', 'Accel World', 'Aura Battler Dunbine', 'Blue Archive', 'Bocchi the Rock!',
    'Brain Powerd', 'Choujuu Sentai Liveman', 'Cowboy Bebop', 'DAEMON X MACHINA', 'Date A Live',
    'DC Comics', 'Demon Slayer: Kimetsu no Yaiba', 'Detective Conan', 'Digimon',
    'Dragon Ball', 'Dragon Ball DAIMA', 'Dragon Ball GT', 'Dragon Ball Super', 'Dragon Ball Z',
    'Dragon Quest', 'Eureka Seven', 'Fate/ series', 'FRAME ARMS', 'Frame Arms Girl',
    'Full Metal Panic!', 'Getter Robo', 'Ghost in the Shell', 'Jujutsu Kaisen', 'Love Live!',
    'One Piece', 'Pokémon', 'Puella Magi Madoka Magica', 'THE iDOLM@STER', 'Yu-Gi-Oh!',
    'No Series', 'Star Wars', 'Neon Genesis Evangelion',
];

$pullHigh = [
    'Mobile Suit Gundam', 'Mobile Suit Gundam Unicorn / Narrative', 'Mobile Suit Gundam Wing',
    'Mobile Suit Gundam SEED / Destiny / Astray', 'Mobile Suit Gundam 00',
    'Mobile Suit Gundam Zeta / ZZ', 'Mobile Suit Gundam GQuuuuuuX',
    'Mobile Suit Gundam: The Witch from Mercury', 'Mobile Suit Victory Gundam',
    'Mobile Suit Gundam: OVAs and Side Stories', 'Mobile Suit Gundam: Char\'s Counterattack',
    'After War Gundam X', 'Mobile Fighter G Gundam', 'Gundam Build Fighters / Try',
    'Turn A Gundam', 'Gundam Reconguista in G',
];

$bandai = PlamodPreorder::query()->active()->where('manufacturer', 'BANDAI HOBBY');

echo "=== SERIES-tab plan (per your screenshot) vs DB ===\n";
echo 'Bandai HOBBY active rows: '.$bandai->count()."\n\n";

echo "--- PULL (iterate SERIES tab one at a time) ---\n";
$ordered = array_merge($plamodSeries, array_map(fn ($n) => ['name' => $n, 'preorder' => null, 'other' => null], $belowFold));
$seen = [];
foreach ($ordered as $entry) {
    $name = $entry['name'];
    if (isset($seen[$name]) || in_array($name, $skipSeries, true)) {
        continue;
    }
    $seen[$name] = true;

    $rows = (clone $bandai)->where('series', $name)->get();
    $total = $rows->count();
    $sparse = $rows->filter(fn ($r) => isSparse($r))->count();
    $full = $total - $sparse;
    $plamodPre = $entry['preorder'];
    $gap = $plamodPre !== null && $plamodPre > $total ? $plamodPre - $total : 0;

    $tier = in_array($name, $pullHigh, true) ? 'HIGH' : 'MED';
    $reasons = [];
    if ($plamodPre !== null && $plamodPre > 0) {
        $reasons[] = "Plamod preorder badge={$plamodPre}";
    }
    if ($gap > 0) {
        $reasons[] = "DB short ~{$gap} vs Plamod";
    }
    if ($sparse > 0) {
        $reasons[] = "{$sparse} sparse";
    }
    if ($total === 0 && ($plamodPre === null || $plamodPre > 0)) {
        $reasons[] = 'zero in DB';
    }
    if (in_array($name, $pullHigh, true)) {
        $reasons[] = 'core Gundam/gunpla';
    }

    if ($tier === 'MED' && $full >= 8 && $sparse <= 1 && ($gap <= 0 || $gap <= 2)) {
        continue;
    }

    echo sprintf(
        "%-4s | plamod_pre=%s | db=%3d full=%2d sparse=%2d | %s | %s\n",
        $tier,
        $plamodPre === null ? '?' : (string) $plamodPre,
        $total,
        $full,
        $sparse,
        $name,
        implode('; ', $reasons) ?: 'coverage check',
    );
}

echo "\n--- SKIP (from screenshot + DB; hub or low gunpla value) ---\n";
foreach (array_merge($plamodSeries, array_map(fn ($n) => ['name' => $n], $skipSeries)) as $entry) {
    $name = is_array($entry) ? $entry['name'] : $entry;
    if (! in_array($name, $skipSeries, true)) {
        continue;
    }
    $total = (clone $bandai)->where('series', $name)->count();
    $plamodPre = null;
    foreach ($plamodSeries as $p) {
        if ($p['name'] === $name) {
            $plamodPre = $p['preorder'];
            break;
        }
    }
    echo sprintf(
        "SKIP | plamod_pre=%s | db=%3d | %s\n",
        $plamodPre === null ? '?' : (string) $plamodPre,
        $total,
        $name,
    );
}

echo "\n--- Suggested iteration order (SERIES tab) ---\n";
$order = [
    'Mobile Suit Gundam',
    'Mobile Suit Gundam Unicorn / Narrative',
    'Mobile Suit Gundam Wing',
    'Mobile Suit Gundam SEED / Destiny / Astray',
    'Mobile Suit Gundam 00',
    'Mobile Suit Gundam Zeta / ZZ',
    'Mobile Suit Gundam GQuuuuuuX',
    'Mobile Suit Gundam: The Witch from Mercury',
    'Mobile Suit Victory Gundam',
    'After War Gundam X',
    '30 Minutes Missions (30MM)',
    '30 Minutes Sisters (30MS)',
    '30 Minutes Fantasy (30MF)',
    '30 Minutes Label',
    'ARMORED CORE',
    'AMAIM Warrior at the Borderline',
    'MACROSS',
    'Sgt. Keroro',
    'SD Gundam Sangokuden Brave Battle Warriors',
    'SD World Heroes',
    'PLANNOSAURUS',
];
foreach ($order as $i => $name) {
    $total = (clone $bandai)->where('series', $name)->count();
    $sparse = (clone $bandai)->where('series', $name)->get()->filter(fn ($r) => isSparse($r))->count();
    echo sprintf('%2d. %s (db=%d sparse=%d)', $i + 1, $name, $total, $sparse)."\n";
}

echo "\n--- User gap SKUs → likely SERIES filter ---\n";
$gaps = [
    '5058260' => 'HGUC #21 → Mobile Suit Gundam or Unicorn',
    '5063823' => 'HGUC #131 → Mobile Suit Gundam',
    '5061575' => 'HGUC Qubeley → Mobile Suit Gundam Zeta / ZZ',
    '5057575' => 'HGAC Maganac → Mobile Suit Gundam Wing',
    '5058266' => 'MG RX-79G → Mobile Suit Gundam',
    '5057617' => 'MG Gouf Custom → Mobile Suit Gundam',
    '5060780' => 'MG Universe Booster → Gundam Build Fighters / Try',
    '0186528' => 'RG Crossbone → Mobile Suit Gundam',
    '0225768' => 'RE Vigna-Ghina → Mobile Suit Gundam: OVAs and Side Stories / F90-F91',
];
foreach ($gaps as $sku => $note) {
    $row = PlamodPreorder::query()->where('sku', $sku)->first(['series', 'dropped_at']);
    echo sprintf(
        "%s | db_series=%s | %s\n",
        $sku,
        $row?->series ?: 'missing',
        $note,
    );
}
