<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;

$suspicious = ['E', 'F', 'M', 'G', 'K', '30'];
$rows = Product::query()
    ->where('main_type', 'model kit')
    ->whereNull('archived_at')
    ->whereIn('grade', $suspicious)
    ->orderBy('grade')
    ->orderBy('sku')
    ->get(['sku', 'description', 'type', 'grade']);

echo 'Suspicious single-letter/30 grades: '.$rows->count()."\n\n";
foreach ($rows->groupBy('grade') as $grade => $items) {
    echo "=== {$grade} ({$items->count()}) ===\n";
    foreach ($items->take(5) as $p) {
        echo "{$p->sku}\t{$p->type}\t{$p->description}\n";
    }
    if ($items->count() > 5) {
        echo '... +'.($items->count() - 5)." more\n";
    }
    echo "\n";
}

$with = Product::query()->where('main_type', 'model kit')->whereNull('archived_at')->whereNotNull('grade')->where('grade', '!=', '')->count();
$total = Product::query()->where('main_type', 'model kit')->whereNull('archived_at')->count();
echo "Grade coverage: {$with}/{$total}\n";
