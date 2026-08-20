<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;

$nonGundamTypes = [
    'POKEMON', '30MM', '30MF', '30MS', '30MP', 'MACROSS', 'KERORO', 'PLAMAX',
    'FIGURE-RISE', 'ARMORED CORE', 'OPTION PARTS', 'OPTION PARTS SET',
    'ACTION BASE', 'SYSTEM BASE', 'LED', 'NIPPER', 'SANDING', 'KEYCHAIN',
    'CCS TOYS', 'SAZABI BUST', 'KUN DX', 'OTHERS', 'SANDING', 'Scribing', 'Weathering',
];

$products = Product::query()
    ->where('main_type', 'model kit')
    ->whereNull('archived_at')
    ->get();

$gundam = $products->filter(function (Product $p) use ($nonGundamTypes): bool {
    $desc = mb_strtoupper((string) $p->description);
    $type = trim((string) ($p->type ?? ''));
    $series = mb_strtoupper(trim((string) ($p->series ?? '')));

    if (in_array($type, $nonGundamTypes, true)) {
        return false;
    }
    if (preg_match('/\bPOK(?:É|E)MON\b/iu', (string) $p->description) === 1) {
        return false;
    }
    if (preg_match('/\b30(?:MM|MF|MS|MP)\b/i', (string) $p->description) === 1) {
        return false;
    }
    if (preg_match('/\bMACROSS\b/i', (string) $p->description) === 1) {
        return false;
    }
    if (preg_match('/\bARMORED\s+CORE\b/i', (string) $p->description) === 1) {
        return false;
    }
    if (preg_match('/\bOPTION\s+PARTS\b/i', (string) $p->description) === 1) {
        return false;
    }
    if (preg_match('/\bACTION\s+BASE\b/i', (string) $p->description) === 1) {
        return false;
    }
    if (preg_match('/\bFIGURE-?RISE\b/i', (string) $p->description) === 1) {
        return false;
    }

    if (str_contains($desc, 'GUNDAM')) {
        return true;
    }
    if (str_contains($series, 'GUNDAM')) {
        return true;
    }
    if (preg_match('/\b(ZAKU|DOM|GOUF|GELGOOG|GEARA|QUBELEY|ZZ|GOUF|RICK\s+DOM)\b/i', (string) $p->description) === 1) {
        return true;
    }

    $gundamTypes = [
        'HG', 'HGUC', 'HGBF', 'HGCE', 'HGAC', 'HGFC', 'HGBC', 'HGAW', 'HGBD', 'HGIBO',
        'MG', 'MGEX', 'MGSD', 'RG', 'PG', 'EG', 'ENTRY GRADE', 'SD', 'BB', 'SDW',
        'EX-Standard', 'SDBF', 'MEGA', 'FM', 'RE', 'NG',
    ];

    return in_array($type, $gundamTypes, true);
});

$byGrade = [];
$hgByType = [];
$missingGrade = [];

foreach ($gundam as $p) {
    $grade = trim((string) ($p->grade ?? ''));
    $key = $grade !== '' ? $grade : '(missing)';
    $byGrade[$key] = ($byGrade[$key] ?? 0) + 1;

    if ($grade === '' || $grade === '(missing)') {
        $missingGrade[] = $p->sku.' | type='.($p->type ?? '').' | '.$p->description;
    }

    if ($grade === 'HG' || in_array((string) ($p->type ?? ''), ['HG', 'HGUC', 'HGBF', 'HGCE', 'HGAC', 'HGFC', 'HGBC', 'HGAW', 'HGBD', 'HGIBO'], true)) {
        $type = trim((string) ($p->type ?? '(null)'));
        $hgByType[$type] = ($hgByType[$type] ?? 0) + 1;
    }
}

ksort($byGrade);
arsort($hgByType);

echo 'Gundam kits: '.$gundam->count()."\n\n";
echo "=== By grade (parent bucket) ===\n";
foreach ($byGrade as $grade => $count) {
    echo str_pad($grade, 12)." {$count}\n";
}

echo "\n=== HG sub-lines (type) ===\n";
foreach ($hgByType as $type => $count) {
    echo str_pad($type, 14)." {$count}\n";
}

if ($missingGrade !== []) {
    echo "\n=== Missing grade (".count($missingGrade).") ===\n";
    foreach ($missingGrade as $line) {
        echo $line."\n";
    }
}
