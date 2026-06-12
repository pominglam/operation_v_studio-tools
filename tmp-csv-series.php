<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;

$mergedPath = __DIR__.'/storage/app/private/plamod/preorder_exports/merged-20260608-141607.csv';
$mfrPath = __DIR__.'/storage/app/private/plamod/manufacturer_preorder_exports/mfr-1-20260608-141223.csv';

function loadCsvBySku(string $path): array
{
    $h = fopen($path, 'rb');
    $header = fgetcsv($h, escape: '\\');
    $map = [];
    foreach ($header as $i => $name) {
        $map[trim((string) $name)] = $i;
    }
    $rows = [];
    while (($row = fgetcsv($h, escape: '\\')) !== false) {
        $sku = trim((string) ($row[$map['SKU']] ?? ''));
        if ($sku === '') {
            continue;
        }
        $rows[$sku] = $row;
    }
    fclose($h);

    return ['map' => $map, 'rows' => $rows];
}

function cell(array $row, array $map, string $col): string
{
    return trim((string) ($row[$map[$col] ?? -1] ?? ''));
}

$merged = loadCsvBySku($mergedPath);
$mfr = loadCsvBySku($mfrPath);

$catalogSkus = array_flip(
    Product::query()->notArchived()->whereNotNull('sku')->pluck('sku')->map(fn ($s) => trim((string) $s))->all()
);

$userMissing = [
    '5058260' => 'HGUC #38 GM Cold Districts',
    '5058006' => 'HGUC Qubeley',
    '5063823' => 'MG RX-79G',
    '5061575' => 'MG Gouf Custom',
    '5057575' => 'HGAC Maganac',
    '5058266' => 'HGUC #131 GM II',
    '5055751' => 'HGUC #152 Jesta Cannon',
    '5063052' => 'RG Skygrasper',
    '5057617' => 'RG Crossbone X1',
    '5060780' => 'HGUC #21 RX-78-2',
    '0186528' => 'MG Universe Booster',
];

echo "=== User missing SKUs: in merged / mfr CSV ===\n";
foreach ($userMissing as $sku => $label) {
    $inMerged = isset($merged['rows'][$sku]);
    $inMfr = isset($mfr['rows'][$sku]);
    $series = $inMerged ? cell($merged['rows'][$sku], $merged['map'], 'Series') : '';
    $category = $inMerged ? cell($merged['rows'][$sku], $merged['map'], 'Category') : '';
    $inCatalog = isset($catalogSkus[$sku]) ? 'yes' : 'no';
    echo sprintf(
        "%s (%s): merged=%s mfr=%s catalog=%s series=%s category=%s\n",
        $sku,
        $label,
        $inMerged ? 'yes' : 'no',
        $inMfr ? 'yes' : 'no',
        $inCatalog,
        $series !== '' ? $series : '(none)',
        $category !== '' ? $category : '(none)',
    );
}

echo "\n=== Merged CSV: BANDAI HOBBY rows with full vs sparse data ===\n";
$bandaiFull = 0;
$bandaiSparse = 0;
$seriesCounts = [];
foreach ($merged['rows'] as $sku => $row) {
    $mfrName = cell($row, $merged['map'], 'Manufacturer');
    if (stripos($mfrName, 'BANDAI HOBBY') === false) {
        continue;
    }
    $series = cell($row, $merged['map'], 'Series');
    $name = cell($row, $merged['map'], 'Product Name');
    $price = cell($row, $merged['map'], 'Price Preorder');
    $key = $series !== '' ? $series : '(blank)';
    $seriesCounts[$key] = ($seriesCounts[$key] ?? 0) + 1;
    if ($price !== '' && $name !== '' && $name !== $sku) {
        $bandaiFull++;
    } else {
        $bandaiSparse++;
    }
}
echo "bandai_full={$bandaiFull} bandai_sparse={$bandaiSparse}\n";
arsort($seriesCounts);
echo "\n--- BANDAI HOBBY by series in merged (top 35) ---\n";
$n = 0;
foreach ($seriesCounts as $series => $cnt) {
    echo sprintf("%4d  %s\n", $cnt, $series);
    if (++$n >= 35) {
        break;
    }
}

echo "\n=== Catalog gunpla SKUs NOT in active merged import ===\n";
$gunplaPattern = '/\b(HGUC|HG |MG |RG |PG |RE |EG |FM |SD |30MM|30MS|30MF|GUNDAM|Gundam)\b/i';
$missingFromImport = 0;
$sample = [];
foreach (array_keys($catalogSkus) as $sku) {
    if (isset($merged['rows'][$sku])) {
        continue;
    }
    $product = Product::query()->where('sku', $sku)->first(['sku', 'name']);
    if (! $product || ! preg_match($gunplaPattern, (string) $product->name)) {
        continue;
    }
    $missingFromImport++;
    if (count($sample) < 15) {
        $sample[] = $sku.' — '.mb_substr((string) $product->name, 0, 60);
    }
}
echo "catalog_gunpla_not_in_merged={$missingFromImport}\n";
foreach ($sample as $line) {
    echo "  {$line}\n";
}
