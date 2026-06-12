<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlamodPreorder;

$htmlPath = __DIR__.'/storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html';
$h = file_get_contents($htmlPath);

function parseCategoryFilters(string $haystack): array
{
    $marker = '\"categories\":';
    $pos = strpos($haystack, $marker);
    if ($pos === false) {
        return [];
    }

    $slice = substr($haystack, $pos + strlen($marker));
    $start = strpos($slice, '[');
    if ($start === false) {
        return [];
    }

    $depth = 0;
    $end = null;
    for ($i = $start; $i < strlen($slice); $i++) {
        if ($slice[$i] === '[') {
            $depth++;
        } elseif ($slice[$i] === ']') {
            $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }

    if ($end === null) {
        return [];
    }

    $raw = substr($slice, $start + 1, $end - $start - 1);
    $chunks = preg_split('/\},\{/', $raw) ?: [];
    $items = [];
    foreach ($chunks as $chunk) {
        if (! preg_match('/id\\\\":(\d+),\\\\"name\\\\":\\\\"((?:\\\\.|[^\\\\"])*)\\\\"/', '{'.$chunk.'}', $m)) {
            continue;
        }
        $name = str_replace(['\\"', "\\'"], ['"', "'"], $m[2]);
        $items[] = ['id' => $m[1], 'name' => $name];
    }

    return $items;
}

function readCsvSkus(string $path): array
{
    if (! is_readable($path)) {
        return [];
    }
    $fh = fopen($path, 'r');
    if ($fh === false) {
        return [];
    }
    $header = fgetcsv($fh);
    $skuIdx = array_search('SKU', $header ?: [], true);
    if ($skuIdx === false) {
        fclose($fh);

        return [];
    }
    $skus = [];
    while (($row = fgetcsv($fh)) !== false) {
        $sku = trim((string) ($row[$skuIdx] ?? ''));
        if ($sku !== '') {
            $skus[$sku] = true;
        }
    }
    fclose($fh);

    return $skus;
}

function isSparse(PlamodPreorder $row): bool
{
    return ! $row->price_preorder
        || trim((string) $row->product_name) === ''
        || trim((string) $row->product_name) === trim((string) $row->sku);
}

$filters = parseCategoryFilters($h);
if ($filters === []) {
    fwrite(STDERR, "Failed to parse category filters\n");
}

$bandai = PlamodPreorder::query()->active()->where('manufacturer', 'BANDAI HOBBY')->get();
$byCategory = [];
$bySeries = [];
foreach ($bandai as $row) {
    $cat = trim((string) ($row->category ?: '')) ?: '(blank)';
    $ser = trim((string) ($row->series ?: '')) ?: '(blank)';
    $byCategory[$cat]['total'] = ($byCategory[$cat]['total'] ?? 0) + 1;
    $bySeries[$ser]['total'] = ($bySeries[$ser]['total'] ?? 0) + 1;
    if (isSparse($row)) {
        $byCategory[$cat]['sparse'] = ($byCategory[$cat]['sparse'] ?? 0) + 1;
        $bySeries[$ser]['sparse'] = ($bySeries[$ser]['sparse'] ?? 0) + 1;
    } else {
        $byCategory[$cat]['full'] = ($byCategory[$cat]['full'] ?? 0) + 1;
        $bySeries[$ser]['full'] = ($bySeries[$ser]['full'] ?? 0) + 1;
    }
}

$mfrSkus = readCsvSkus(__DIR__.'/storage/app/private/plamod/manufacturer_preorder_exports/mfr-1-20260608-141223.csv');
$hubSkus = readCsvSkus(__DIR__.'/storage/app/private/plamod/preorders-export-latest.csv');

$skip = [
    'Decals', 'Tools', 'Action Base', 'Builder Parts', 'Option Parts Set',
    'EVENT EXCLUSIVE', 'G-BASE EXCLUSIVE', 'G-BASE World Tour EXCLUSIVE',
    'Pokemon', 'One Piece', 'Star Wars', 'Demon Slayer Model Kit',
    'Dragon Ball DAIMA Plastic Model', 'Gunpla-kun', 'PLANNOSAURUS',
    'Best Hit Chronicle', 'Girl Gun Lady', 'Wataru Series', 'ULTIMAGEAR',
    'ULTRAMAN the Armour of Legends', 'Star Blazers', 'UCHG',
    'Figure-rise', 'Imaginary Skeleton', 'LBX', 'PR (Petit Ritz)',
    'HGPG (Petit\'gguy)', 'Others', 'HG Others', 'Mecha Collection',
    'Plastic Model Collection', 'EX Model', 'FG (First Grade)', 'HY2M',
    'HP (Haropla)', 'HSV (Mobile Suit Variations)', 'High Grade Mechanics',
    'Gundam Collection', 'Gundam Assemble', 'Build Metaverse', 'NG 1/100',
    'NG 1/144', 'NG 1/60', 'Mega Size', 'HG 1/60', 'HG 1/72',
];

$pullHigh = [
    'HGUC', 'HGAC (After Colony)', 'MG', 'RG', 'Gundam F90-F91',
    'HG 1/144', 'HGCE (Cosmic Era)', 'HGIBO (Iron-Blooded Orphans)',
    'HGTWFM (the Witch from Mercury)', 'HG00 (Gundam 00)', 'HGBF (Build Fighters)',
    'HGBD (Build Divers)', 'HGAGE (Gundam AGE)', 'V Gundam', 'Turn A Gundam',
    'G Gundam', 'Gundam X', 'Gundam 008x', 'Gundam 1st', 'GUNDAM SIDE-F',
    '30 Minutes Label', 'Entry Grade', 'Full Mechanics', 'Hi-Resolution Model',
    'HGFC (Future Century)', 'HGGBB (Gundam Breaker Battlogue)', 'HG 1/100',
    'PG', 'SD BB', 'SD Sangokuden', 'SD World Heroes', 'SD Cross Silhouette',
    'SD EX-Standard', 'SD G Generation',
];

$pullMed = [
    'MACROSS', // not in list - skip
];

echo "=== BASELINE (no code changes) ===\n";
echo 'Active preorders total: '.PlamodPreorder::query()->active()->count()."\n";
echo 'Bandai HOBBY active: '.$bandai->count().' (sparse: '.$bandai->filter(fn ($r) => isSparse($r))->count().")\n";
echo 'Non-Bandai / blank mfr: '.PlamodPreorder::query()->active()->where(function ($q): void {
    $q->whereNull('manufacturer')->orWhere('manufacturer', '!=', 'BANDAI HOBBY');
})->count()."\n";
echo 'Current manufacturer export: '.count($mfrSkus).' SKUs (single filter: Plastic Model Kits / categories=1)'."\n";
echo 'Hub snapshot (Jun 5): '.count($hubSkus)." SKUs\n";
echo 'Plamod manufacturer CATEGORY filters available: '.count($filters)."\n\n";

echo "=== PULL — iterate these CATEGORY filters one at a time (Bandai mfr=1, Preorder tab) ===\n";
foreach ($filters as $filter) {
    $name = $filter['name'];
    if (in_array($name, $skip, true)) {
        continue;
    }

    $db = $byCategory[$name] ?? ['total' => 0, 'full' => 0, 'sparse' => 0];
    $total = (int) ($db['total'] ?? 0);
    $sparse = (int) ($db['sparse'] ?? 0);
    $full = (int) ($db['full'] ?? 0);

    $tier = in_array($name, $pullHigh, true) ? 'HIGH' : 'MED';
    $reasons = [];

    if (in_array($name, $pullHigh, true)) {
        $reasons[] = 'core gunpla line';
    }
    if ($sparse > 0) {
        $reasons[] = "{$sparse} sparse in DB";
    }
    if ($total === 0) {
        $reasons[] = 'not keyed in DB category field yet';
    }
    if ($total > 0 && $sparse >= max(2, (int) ceil($total * 0.25))) {
        $reasons[] = 'high sparse ratio';
    }
    if ($name === 'Gundam F90-F91') {
        $reasons[] = 'fills Vigna-Ghina-type gaps';
    }

    if ($tier === 'MED' && $full >= 8 && $sparse <= 1) {
        continue;
    }

    echo sprintf(
        "%-4s id=%-4s | db %3d (full %2d sparse %2d) | %s | %s\n",
        $tier,
        $filter['id'],
        $total,
        $full,
        $sparse,
        $name,
        implode('; ', $reasons),
    );
}

echo "\n=== SKIP — do not bother with manufacturer series pull for these ===\n";
foreach ($filters as $filter) {
    $name = $filter['name'];
    if (! in_array($name, $skip, true)) {
        continue;
    }
    $db = $byCategory[$name] ?? ['total' => 0, 'full' => 0, 'sparse' => 0];
    echo sprintf(
        "id=%-4s | db %3d | %s | accessories / exclusives / non-gunpla / hub-covered\n",
        $filter['id'],
        (int) ($db['total'] ?? 0),
        $name,
    );
}

echo "\nOther skip buckets (not Bandai manufacturer category iteration):\n";
echo "- 663 rows: manufacturers other than BANDAI HOBBY → keep hub CSV only\n";
echo "- 248 rows: blank manufacturer → hub CSV only\n";
echo "- Top hub categories: Blind Box (67), Scale Figure (63), Nendoroid (38), etc.\n";

echo "\n=== OPTIONAL — SERIES tab pulls (by series name, second pass) ===\n";
$seriesPull = [
    ['Mobile Suit Gundam', 'HIGH', '12/27 sparse; original HG/RG gaps'],
    ['Mobile Suit Gundam Unicorn / Narrative', 'HIGH', 'HGUC Unicorn-era kits'],
    ['Mobile Suit Gundam Wing', 'HIGH', 'HGAC kits map here too'],
    ['Mobile Suit Gundam SEED / Destiny / Astray', 'MED', 'large line, mostly full'],
    ['30 Minutes Fantasy (30MF)', 'MED', '12 sparse'],
    ['30 Minutes Missions (30MM)', 'MED', '6 sparse'],
    ['30 Minutes Sisters (30MS)', 'MED', '6 sparse'],
    ['After War Gundam X', 'MED', '2 sparse'],
    ['Mobile Suit Gundam GQuuuuuuX', 'MED', 'new line'],
    ['Sgt. Keroro', 'LOW', 'mostly full; niche'],
    ['Pokémon', 'SKIP', 'hub-sourced Bandai goods, not gunpla page'],
    ['No Series', 'SKIP', 'meta bucket, not a filter'],
];
foreach ($seriesPull as [$series, $tier, $why]) {
    $db = $bySeries[$series] ?? ['total' => 0, 'full' => 0, 'sparse' => 0];
    echo sprintf(
        "%-4s | db %3d (full %2d sparse %2d) | %s | %s\n",
        $tier,
        (int) ($db['total'] ?? 0),
        (int) ($db['full'] ?? 0),
        (int) ($db['sparse'] ?? 0),
        $series,
        $why,
    );
}

echo "\n=== Your missing search SKUs — where they live today ===\n";
$gaps = [
    '5058260' => 'HGUC #21',
    '5058006' => 'HGUC #38 sparse shell',
    '5063823' => 'HGUC #131',
    '5061575' => 'HGUC Qubeley',
    '5057575' => 'HGAC Maganac',
    '5058266' => 'MG RX-79G',
    '5057617' => 'MG Gouf Custom',
    '5060780' => 'MG Universe Booster',
    '0186528' => 'RG Crossbone',
    '0225768' => 'RE Vigna-Ghina (sparse)',
];
foreach ($gaps as $sku => $label) {
    $row = PlamodPreorder::query()->where('sku', $sku)->first();
    echo sprintf(
        "%s %s | db=%s | mfr=%s | hub=%s\n",
        $sku,
        $label,
        ($row && ! $row->dropped_at) ? 'active' : (($row && $row->dropped_at) ? 'dropped' : 'missing'),
        isset($mfrSkus[$sku]) ? 'yes' : 'no',
        isset($hubSkus[$sku]) ? 'yes' : 'no',
    );
}

echo "\nSuggested iteration order: HGUC → Gundam F90-F91 → HGAC → MG → RG → HG 1/144 → remaining HIGH lines → SERIES tab pass for Mobile Suit Gundam / Unicorn.\n";
