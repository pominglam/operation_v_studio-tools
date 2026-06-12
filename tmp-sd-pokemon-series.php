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

function bandaiSeriesStats(string $series): array
{
    $rows = PlamodPreorder::query()
        ->active()
        ->where('manufacturer', 'BANDAI HOBBY')
        ->where('series', $series)
        ->get();
    $sparse = $rows->filter(fn ($r) => isSparse($r))->count();

    return [
        'total' => $rows->count(),
        'full' => $rows->count() - $sparse,
        'sparse' => $sparse,
    ];
}

function pokemonStats(): array
{
    $rows = PlamodPreorder::query()->active()->where('series', 'Pokémon')->get();
    $bandai = $rows->filter(fn ($r) => strtoupper(trim((string) $r->manufacturer)) === 'BANDAI HOBBY');
    $other = $rows->reject(fn ($r) => strtoupper(trim((string) $r->manufacturer)) === 'BANDAI HOBBY');

    return [
        'total' => $rows->count(),
        'bandai_total' => $bandai->count(),
        'bandai_sparse' => $bandai->filter(fn ($r) => isSparse($r))->count(),
        'non_bandai' => $other->count(),
        'manufacturers' => $other->groupBy(fn ($r) => trim((string) ($r->manufacturer ?: '(blank)')))->map->count()->sortDesc()->all(),
    ];
}

$h = @file_get_contents(__DIR__.'/storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html') ?: '';

// Find series/category names in HTML mentioning SD, Silhouette, Pokemon, Sangokuden, etc.
$keywords = ['SD ', 'Silhouette', 'Sangokuden', 'Pokemon', 'Pokémon', 'BB Senshi', 'EX-Standard', 'World Heroes', 'Cross Silhouette', 'G Generation', 'Petit'];
echo "=== Names in manufacturer HTML matching SD / Silhouette / Pokemon ===\n";
foreach ($keywords as $kw) {
    $pos = 0;
    $hits = 0;
    while (($p = stripos($h, $kw, $pos)) !== false && $hits < 4) {
        $ctx = substr($h, max(0, $p - 20), 100);
        $ctx = preg_replace('/\s+/', ' ', $ctx) ?? $ctx;
        echo "  [{$kw}] ...{$ctx}...\n";
        $pos = $p + strlen($kw);
        $hits++;
    }
}

// CATEGORY tab product lines (SD / silhouette live here too)
if (preg_match('/\\"categories\\":\[(\{.*?\})\],\\"series\\"/s', $h, $catMatch)) {
    $catBlob = $catMatch[1];
} else {
    $catBlob = '';
}
$catNames = [];
if ($catBlob !== '' && preg_match_all('/\\"name\\":\\"((?:\\\\.|[^\\\\"])*)\\"/', $catBlob, $cm)) {
    foreach ($cm[1] as $raw) {
        $name = stripcslashes(str_replace(['\\"'], ['"'], $raw));
        if (preg_match('/SD|Silhouette|Sangokuden|Pokemon|Pokémon|BB|EX-Standard|World Heroes|G Generation|Petit/i', $name)) {
            $catNames[] = $name;
        }
    }
}
echo "\n=== CATEGORY filters (SD / silhouette / pokemon related) ===\n";
foreach (array_unique($catNames) as $n) {
    echo "  {$n}\n";
}

// All Bandai series in DB that look SD/silhouette/pokemon
echo "\n=== DB Bandai series matching SD / silhouette / pokemon ===\n";
$dbSeries = PlamodPreorder::query()
    ->active()
    ->where('manufacturer', 'BANDAI HOBBY')
    ->selectRaw('series, COUNT(*) as c')
    ->groupBy('series')
    ->orderBy('series')
    ->pluck('c', 'series');
foreach ($dbSeries as $series => $cnt) {
    $s = (string) $series;
    if (preg_match('/SD|Pokémon|Pokemon|Silhouette|Sangokuden|World Heroes|BB|30 Min/i', $s)) {
        $st = bandaiSeriesStats($s);
        echo sprintf(
            "  %s | db=%d full=%d sparse=%d\n",
            $s,
            $st['total'],
            $st['full'],
            $st['sparse'],
        );
    }
}

echo "\n=== Pokémon breakdown ===\n";
$pk = pokemonStats();
echo 'All active Pokémon rows: '.$pk['total']."\n";
echo 'BANDAI HOBBY Pokémon: '.$pk['bandai_total'].' (sparse: '.$pk['bandai_sparse'].")\n";
echo 'Non-Bandai Pokémon: '.$pk['non_bandai']."\n";
foreach ($pk['manufacturers'] as $mfr => $cnt) {
    echo "  {$mfr}: {$cnt}\n";
}

// Full tier lists for final report
$tier1 = [
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
];

$tier2 = [
    '30 Minutes Missions (30MM)',
    '30 Minutes Sisters (30MS)',
    '30 Minutes Fantasy (30MF)',
    '30 Minutes Label',
];

$tier3 = [
    'ARMORED CORE',
    'AMAIM Warrior at the Borderline',
    'Sgt. Keroro',
    'MACROSS',
    'SD Gundam Sangokuden Brave Battle Warriors',
    'SD World Heroes',
    'PLANNOSAURUS',
    'Armored Trooper VOTOMs',
    'Code Geass: Lelouch of the Rebellion',
    'Super Robot Wars',
    'Ultraman',
    'Doraemon',
    'Pokémon',
];

// SD / silhouette — SERIES tab names + CATEGORY lines that map to series field
$sdSeries = [
    'SD Gundam Sangokuden Brave Battle Warriors',
    'SD World Heroes',
    'SD BB',
    'SD Cross Silhouette',
    'SD EX-Standard',
    'SD G Generation',
    'SD Gundam',
    'Mobile Suit Gundam the Origin',
];

$sdCategories = [
    'SD BB',
    'SD Cross Silhouette',
    'SD EX-Standard',
    'SD G Generation',
    'SD Sangokuden',
    'SD World Heroes',
    'SD Gundam Sangokuden Brave Battle Warriors',
];

echo "\n=== TIER 1 / 2 / 3 + SD / Pokémon (Bandai) summary ===\n";
foreach ([1 => $tier1, 2 => $tier2, 3 => $tier3] as $tier => $list) {
    echo "\n--- TIER {$tier} ---\n";
    foreach ($list as $name) {
        if ($name === 'Pokémon') {
            echo sprintf(
                "PULL | plamod=series tab | db_bandai=%d sparse=%d | %s | Bandai Pokémon kits only (skip non-Bandai hub rows)\n",
                $pk['bandai_total'],
                $pk['bandai_sparse'],
                $name,
            );

            continue;
        }
        $st = bandaiSeriesStats($name);
        echo sprintf(
            "PULL | db=%3d full=%2d sparse=%2d | %s\n",
            $st['total'],
            $st['full'],
            $st['sparse'],
            $name,
        );
    }
}

echo "\n--- SD GUNDAM / SILHOUETTE (extra check) ---\n";
echo "SERIES-tab names:\n";
foreach ($sdSeries as $name) {
    $st = bandaiSeriesStats($name);
    $note = $st['total'] === 0 ? 'NOT IN DB — likely needs pull' : ($st['sparse'] > 0 ? "{$st['sparse']} sparse" : 'in DB');
    echo sprintf("  PULL | db=%3d full=%2d sparse=%2d | %s | %s\n", $st['total'], $st['full'], $st['sparse'], $name, $note);
}

echo "\nCATEGORY-tab lines (may not appear as series field; still pull via series tab if listed):\n";
foreach ($sdCategories as $cat) {
    $cnt = PlamodPreorder::query()->active()->where('manufacturer', 'BANDAI HOBBY')->where('category', $cat)->count();
    $sparse = PlamodPreorder::query()->active()->where('manufacturer', 'BANDAI HOBBY')->where('category', $cat)->get()
        ->filter(fn ($r) => isSparse($r))->count();
    echo sprintf("  CHECK | db_by_category=%3d sparse=%2d | category=%s\n", $cnt, $sparse, $cat);
}

// Bandai rows where category is SD line but series differs
echo "\nSD-category rows grouped by series field:\n";
$sdCatRows = PlamodPreorder::query()
    ->active()
    ->where('manufacturer', 'BANDAI HOBBY')
    ->where(function ($q): void {
        $q->where('category', 'like', 'SD %')
            ->orWhere('category', 'SD Sangokuden')
            ->orWhere('category', 'SD World Heroes');
    })
    ->get();
foreach ($sdCatRows->groupBy(fn ($r) => trim((string) ($r->series ?: '(blank)'))) as $series => $grp) {
    echo sprintf("  %s: %d rows (categories: %s)\n", $series, $grp->count(), $grp->pluck('category')->unique()->implode(', '));
}
