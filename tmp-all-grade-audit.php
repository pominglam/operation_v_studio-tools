<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Support\Products\ProductGradeResolver;
use App\Services\Products\ProductTypeDerivationService;

$resolver = new ProductGradeResolver(new ProductTypeDerivationService);

$products = Product::query()
    ->where('main_type', 'model kit')
    ->whereNull('archived_at')
    ->orderBy('sku')
    ->get();

$issues = [];
$byIssue = [];
$byMainTypeAll = Product::query()->whereNull('archived_at')->selectRaw('main_type, count(*) c')->groupBy('main_type')->orderByDesc('c')->pluck('c', 'main_type');

foreach ($products as $product) {
    $resolved = $resolver->resolveFromProduct($product);
    $stored = trim((string) ($product->grade ?? ''));
    $needs = $resolver->needsCorrection($product);

    if ($stored === '' && $resolved === null) {
        $issue = 'missing_grade_no_rule';
    } elseif ($stored === '' && $resolved !== null) {
        $issue = 'missing_grade';
    } elseif ($needs) {
        $storedNorm = mb_strtoupper($stored);
        if (preg_match('/^[A-Z0-9]{1,2}$/', $storedNorm) === 1
            && ! in_array($storedNorm, ['HG', 'MG', 'RG', 'PG', 'EG', 'SD', 'FM', 'RE', 'NG'], true)) {
            $issue = 'suspicious_grade';
        } else {
            $issue = 'mismatch';
        }
    } else {
        continue;
    }

    $row = [
        'sku' => $product->sku,
        'description' => $product->description,
        'type' => $product->type,
        'grade' => $product->grade,
        'expected' => $resolved,
        'issue' => $issue,
    ];
    $issues[] = $row;
    $byIssue[$issue][] = $row;
}

$nullGradeByType = Product::query()
    ->where('main_type', 'model kit')
    ->whereNull('archived_at')
    ->where(function ($q): void {
        $q->whereNull('grade')->orWhere('grade', '');
    })
    ->selectRaw("COALESCE(NULLIF(TRIM(type), ''), '(null)') as type_label, COUNT(*) as c")
    ->groupBy('type_label')
    ->orderByDesc('c')
    ->get();

echo 'Model kits total: '.$products->count()."\n";
echo 'Grade issues remaining: '.count($issues)."\n\n";

foreach ($byIssue as $issueType => $rows) {
    echo '=== '.$issueType.' ('.count($rows).") ===\n";
    foreach ($rows as $row) {
        echo $row['sku']
            ."\tgrade=".($row['grade'] ?? '(null)')
            ."\ttype=".($row['type'] ?? '(null)')
            ."\texpected=".($row['expected'] ?? '(none)')
            ."\t".$row['description']
            ."\n";
    }
    echo "\n";
}

echo "=== Missing grade by type ===\n";
foreach ($nullGradeByType as $row) {
    echo str_pad((string) $row->type_label, 20)." {$row->c}\n";
}

echo "\n=== All main_type counts ===\n";
foreach ($byMainTypeAll as $mainType => $count) {
    $label = $mainType !== '' ? $mainType : '(blank)';
    echo str_pad($label, 20)." {$count}\n";
}

$nonModelKitMissing = Product::query()
    ->whereNull('archived_at')
    ->where('main_type', '!=', 'model kit')
    ->whereNotNull('grade')
    ->where('grade', '!=', '')
    ->count();

echo "\nNon-model-kit rows with grade set: {$nonModelKitMissing}\n";

$artifactLike = Product::query()
    ->whereNull('archived_at')
    ->where(function ($q): void {
        $q->where('description', 'like', '%Artifact%')
            ->orWhere('description', 'like', '%Shokugan%');
    })
    ->get(['sku', 'description', 'main_type', 'type', 'grade']);

echo "\n=== Artifact / Shokugan ===\n";
foreach ($artifactLike as $p) {
    echo "{$p->sku}\tmain={$p->main_type}\ttype=".($p->type ?? '')."\tgrade=".($p->grade ?? '(null)')."\t{$p->description}\n";
}

file_put_contents(
    __DIR__.'/storage/app/private/tmp-all-grade-audit.json',
    json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
