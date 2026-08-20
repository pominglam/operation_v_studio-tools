<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Services\Products\ProductTypeDerivationService;

/**
 * Map detailed product type / title token to canonical storefront grade bucket.
 *
 * @return list<string>
 */
function canonicalGradesFromName(string $name): array
{
    $name = trim($name);
    if ($name === '') {
        return [];
    }

    $svc = new ProductTypeDerivationService;
    $derivedType = $svc->deriveFromName($name);

    $upper = mb_strtoupper($name);

    $grades = [];

    $patterns = [
        '/\bENTRY\s+GRADE\b/i' => 'EG',
        '/\bHIGH\s+GRADE\s*\(\s*HG\s*\)/i' => 'HG',
        '/\bREAL\s+GRADE\s*\(\s*RG\s*\)/i' => 'RG',
        '/\bMASTER\s+GRADE\s*\(\s*MG\s*\)/i' => 'MG',
        '/\bMGEX\b/i' => 'MGEX',
        '/\bMGSD\b/i' => 'MGSD',
        '/\bFULL\s+MECHANICS\b/i' => 'FM',
        '/\bRE\s+1\s*\/\s*100\b/i' => 'RE',
        '/\bMEGA\s+SIZE\b/i' => 'MEGA',
        '/\bEX[-\s]?STANDARD\b/i' => 'EX-Standard',
        '/\bORPHANS\s+HG\b/i' => 'HG',
        '/\b(?:^|\s)SD(?:\s|$)/i' => 'SD',
        '/\bBB\d+/i' => 'SD',
        '/\bSDBF\b/i' => 'SD',
        '/\bPG\b/i' => 'PG',
        '/\bRG\b/i' => 'RG',
        '/\bMG\b/i' => 'MG',
        '/\bHGUC\b/i' => 'HG',
        '/\bHGBF\b/i' => 'HG',
        '/\bHGCE\b/i' => 'HG',
        '/\bHGAC\b/i' => 'HG',
        '/\bHGFC\b/i' => 'HG',
        '/\bHGBC\b/i' => 'HG',
        '/\bHGAW\b/i' => 'HG',
        '/\bHGBD\b/i' => 'HG',
        '/\b(?:^|\s)HG(?:\s|$|\d)/i' => 'HG',
    ];

    foreach ($patterns as $pattern => $grade) {
        if (preg_match($pattern, $name) === 1) {
            $grades[] = $grade;
        }
    }

    if ($derivedType !== null) {
        $typeToGrade = [
            'EG' => 'EG',
            'ENTRY GRADE' => 'EG',
            'MG' => 'MG',
            'MGEX' => 'MGEX',
            'MGSD' => 'MGSD',
            'RG' => 'RG',
            'PG' => 'PG',
            'MEGA' => 'MEGA',
            'MEGA SIZE MODEL' => 'MEGA',
            'FM' => 'FM',
            'RE' => 'RE',
            'HG' => 'HG',
            'HGUC' => 'HG',
            'HGBF' => 'HG',
            'HGCE' => 'HG',
            'HGAC' => 'HG',
            'HGFC' => 'HG',
            'HGBC' => 'HG',
            'HGAW' => 'HG',
            'HGBD' => 'HG',
            'Orphans HG' => 'HG',
            'SD' => 'SD',
            'BB' => 'SD',
            'SDW' => 'SD',
            'EX-Standard' => 'EX-Standard',
            'SDBF' => 'SD',
        ];
        if (isset($typeToGrade[$derivedType])) {
            $grades[] = $typeToGrade[$derivedType];
        }
    }

    return array_values(array_unique($grades));
}

/** @return list<string> */
function normalizeStoredGrade(?string $grade): array
{
    $grade = $grade !== null ? mb_strtoupper(trim($grade)) : '';
    if ($grade === '') {
        return [];
    }

    $map = [
        'ENTRY GRADE' => 'EG',
        'EG' => 'EG',
        'HGUC' => 'HG',
        'HGBF' => 'HG',
        'HGCE' => 'HG',
        'HGAC' => 'HG',
        'HGFC' => 'HG',
        'HGBC' => 'HG',
        'HGAW' => 'HG',
        'HGBD' => 'HG',
        'HG' => 'HG',
        'RG' => 'RG',
        'MG' => 'MG',
        'MGEX' => 'MGEX',
        'MGSD' => 'MGSD',
        'PG' => 'PG',
        'MEGA' => 'MEGA',
        'FM' => 'FM',
        'RE' => 'RE',
        'SD' => 'SD',
        'BB' => 'SD',
        'EX-STANDARD' => 'EX-Standard',
        'NG' => 'NG',
    ];

    if (isset($map[$grade])) {
        return [$map[$grade]];
    }

    return [$grade];
}

function isGundamKit(Product $product): bool
{
    $desc = mb_strtoupper((string) $product->description);
    $type = mb_strtoupper(trim((string) ($product->type ?? '')));
    $series = mb_strtoupper(trim((string) ($product->series ?? '')));

    $nonGundamTypes = [
        'POKEMON', '30MM', '30MF', '30MS', '30MP', 'MACROSS', 'KERORO', 'PLAMAX',
        'FIGURE-RISE', 'ARMORED CORE', 'OPTION PARTS', 'OPTION PARTS SET',
        'ACTION BASE', 'SYSTEM BASE', 'LED', 'NIPPER', 'SANDING', 'KEYCHAIN',
        'CCS TOYS', 'SAZABI BUST', 'KUN DX', 'OTHERS',
    ];

    if (in_array($type, $nonGundamTypes, true)) {
        return false;
    }

    if (preg_match('/\bPOK(?:É|E)MON\b/iu', (string) $product->description) === 1) {
        return false;
    }
    if (preg_match('/\b30(?:MM|MF|MS|MP)\b/i', (string) $product->description) === 1) {
        return false;
    }
    if (preg_match('/\bMACROSS\b/i', (string) $product->description) === 1) {
        return false;
    }
    if (preg_match('/\bARMORED\s+CORE\b/i', (string) $product->description) === 1) {
        return false;
    }
    if (preg_match('/\bOPTION\s+PARTS\b/i', (string) $product->description) === 1) {
        return false;
    }
    if (preg_match('/\bACTION\s+BASE\b/i', (string) $product->description) === 1) {
        return false;
    }
    if (preg_match('/\bSYSTEM\s+BASE\b/i', (string) $product->description) === 1) {
        return false;
    }
    if (preg_match('/\bFIGURE-?RISE\b/i', (string) $product->description) === 1) {
        return false;
    }

    if (str_contains($desc, 'GUNDAM')) {
        return true;
    }
    if (str_contains($series, 'GUNDAM')) {
        return true;
    }
    if (preg_match('/\b(ZAKU|DOM|GOUF|GELGOOG|GEARA|QUBELEY|ZZ|Z\'?GOK|GM\s|RICK\s+DOM)\b/i', (string) $product->description) === 1) {
        return true;
    }

    $gundamTypes = [
        'HG', 'HGUC', 'HGBF', 'HGCE', 'HGAC', 'HGFC', 'HGBC', 'HGAW', 'HGBD',
        'MG', 'MGEX', 'MGSD', 'RG', 'PG', 'EG', 'ENTRY GRADE', 'SD', 'BB', 'SDW',
        'EX-STANDARD', 'MEGA', 'FM', 'RE', 'ORPHANS HG',
    ];

    if (in_array($type, $gundamTypes, true) && str_contains($desc, 'GUNDAM')) {
        return true;
    }

    return false;
}

function gradeFromType(?string $type): ?string
{
    $type = $type !== null ? trim($type) : '';
    if ($type === '') {
        return null;
    }

    $map = [
        'EG' => 'EG',
        'ENTRY GRADE' => 'EG',
        'HG' => 'HG',
        'HGUC' => 'HG',
        'HGBF' => 'HG',
        'HGCE' => 'HG',
        'HGAC' => 'HG',
        'HGFC' => 'HG',
        'HGBC' => 'HG',
        'HGAW' => 'HG',
        'HGBD' => 'HG',
        'Orphans HG' => 'HG',
        'RG' => 'RG',
        'MG' => 'MG',
        'MGEX' => 'MGEX',
        'MGSD' => 'MGSD',
        'PG' => 'PG',
        'MEGA' => 'MEGA',
        'FM' => 'FM',
        'RE' => 'RE',
        'SD' => 'SD',
        'BB' => 'SD',
        'SDW' => 'SD',
        'SDBF' => 'SD',
        'EX-Standard' => 'EX-Standard',
    ];

    return $map[$type] ?? null;
}

/** @return array{issue:string,expected:array<int,string>,stored:array<int,string>}|null */
function gradeIssue(Product $product): ?array
{
    $expected = canonicalGradesFromName((string) $product->description);
    $fromType = gradeFromType($product->type);
    if ($fromType !== null) {
        $expected = array_values(array_unique([...$expected, $fromType]));
    }
    $stored = normalizeStoredGrade($product->grade);
    $storedRaw = trim((string) ($product->grade ?? ''));

    if ($expected === [] && $storedRaw === '') {
        return ['issue' => 'missing_both', 'expected' => [], 'stored' => []];
    }

    if ($expected === [] && $storedRaw !== '') {
        return ['issue' => 'unexpected_grade', 'expected' => [], 'stored' => $stored];
    }

    if ($expected !== [] && $storedRaw === '') {
        return ['issue' => 'missing_grade', 'expected' => $expected, 'stored' => []];
    }

    $intersect = array_values(array_intersect($expected, $stored));
    if ($intersect !== []) {
        return null;
    }

    // Suspicious single-letter or numeric grades from bad parsing.
    if (preg_match('/^[A-Z0-9]{1,2}$/', $storedRaw) === 1 && ! in_array($storedRaw, ['HG', 'MG', 'RG', 'PG', 'EG', 'SD', 'FM', 'RE', 'NG'], true)) {
        return ['issue' => 'suspicious_grade', 'expected' => $expected, 'stored' => $stored];
    }

    return ['issue' => 'mismatch', 'expected' => $expected, 'stored' => $stored];
}

$products = Product::query()
    ->where('main_type', 'model kit')
    ->whereNull('archived_at')
    ->orderBy('sku')
    ->get();

$gundam = $products->filter(fn (Product $p): bool => isGundamKit($p));

$issues = [];
foreach ($gundam as $product) {
    $issue = gradeIssue($product);
    if ($issue === null) {
        continue;
    }
    $issues[] = [
        'sku' => $product->sku,
        'description' => $product->description,
        'type' => $product->type,
        'grade' => $product->grade,
        'series' => $product->series,
        'scale' => $product->scale,
        'issue' => $issue['issue'],
        'expected' => implode('|', $issue['expected']),
        'stored_norm' => implode('|', $issue['stored']),
    ];
}

echo 'Total model kits: '.$products->count().PHP_EOL;
echo 'Gundam kits identified: '.$gundam->count().PHP_EOL;
echo 'Grade issues: '.count($issues).PHP_EOL.PHP_EOL;

$byIssue = [];
foreach ($issues as $row) {
    $byIssue[$row['issue']][] = $row;
}

foreach ($byIssue as $issueType => $rows) {
    echo '=== '.$issueType.' ('.count($rows).') ==='.PHP_EOL;
    foreach ($rows as $row) {
        echo $row['sku']
            ."\tgrade=".($row['grade'] ?? '(null)')
            ."\ttype=".($row['type'] ?? '(null)')
            ."\texpected=".$row['expected']
            ."\t".$row['description']
            .PHP_EOL;
    }
    echo PHP_EOL;
}

$out = __DIR__.'/storage/app/private/tmp-gundam-grade-audit.json';
file_put_contents($out, json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Wrote '.$out.PHP_EOL;
