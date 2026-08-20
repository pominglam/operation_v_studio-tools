<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use Illuminate\Support\Collection;

$hgTypes = [
    'HG', 'HGUC', 'HGBF', 'HGCE', 'HGAC', 'HGFC', 'HGBC', 'HGAW', 'HGBD', 'Orphans HG',
];

$base = Product::query()
    ->where('main_type', 'model kit')
    ->whereNull('archived_at');

/** @var Collection<int, object{type: string|null, kit_count: int}> $byType */
$byType = (clone $base)
    ->where(function ($q) use ($hgTypes): void {
        $q->where('grade', 'HG')
            ->orWhereIn('type', $hgTypes);
    })
    ->selectRaw('type, COUNT(*) AS kit_count')
    ->groupBy('type')
    ->orderByDesc('kit_count')
    ->get();

$total = (int) (clone $base)
    ->where(function ($q) use ($hgTypes): void {
        $q->where('grade', 'HG')
            ->orWhereIn('type', $hgTypes);
    })
    ->count();

$gradeHgOnly = (clone $base)->where('grade', 'HG')->count();

$orphans = (clone $base)->where('type', 'Orphans HG')->count();

$ngHgScale = (clone $base)
    ->where('grade', 'NG')
    ->where('scale', '1/144')
    ->where('description', 'like', '%GUNDAM%')
    ->count();

$missingType = (clone $base)
    ->where('grade', 'HG')
    ->where(function ($q): void {
        $q->whereNull('type')->orWhere('type', 'Others');
    })
    ->get(['sku', 'description', 'type', 'grade']);

echo "HG family total (grade=HG or HG* type): {$total}\n";
echo "grade=HG rows: {$gradeHgOnly}\n\n";

echo "=== By type sub-line ===\n";
foreach ($byType as $row) {
    $type = $row->type ?? '(null)';
    echo str_pad((string) $type, 14)." {$row->kit_count}\n";
}

echo "\n=== grade=HG but type missing/Others (".count($missingType).") ===\n";
foreach ($missingType as $p) {
    echo "{$p->sku}\t".($p->type ?? '(null)')."\t{$p->description}\n";
}

echo "\n=== Other Bandai HG sub-lines NOT in catalog ===\n";
$knownIndustry = ['HGGTO', 'HGIBO', 'HGBD:R', 'HGGG', 'HGTW', 'HGFA', 'HG00', 'HGAGE', 'HGWFM'];
$inCatalog = $byType->pluck('type')->filter()->map(fn ($t) => (string) $t)->all();
foreach ($knownIndustry as $line) {
    if (! in_array($line, $inCatalog, true)) {
        echo "{$line}: 0 in ERP\n";
    }
}

echo "\nOrphans HG type count: {$orphans}\n";
echo "NG 1/144 Gundam (possible HG-adjacent): {$ngHgScale}\n";
